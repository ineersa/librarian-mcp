<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MemoryGenerationFailureCode;
use App\Entity\MemorySourceKind;
use App\Index\ProjectIndexManager;
use App\Message\ReflectMemoryGenerationMessage;
use App\ObservationalMemory\Compaction\DropperPipeline;
use App\ObservationalMemory\Compaction\ReflectorException;
use App\ObservationalMemory\Compaction\ReflectorPipeline;
use App\ObservationalMemory\MemorySessionLock;
use App\ObservationalMemory\MemorySessionWorkOutcome;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryTokenLimits;
use App\ObservationalMemory\Model\PinnedMemorySettings;
use App\ObservationalMemory\Observer\OmTokenEstimator;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\MemorySessionRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use App\ObservationalMemory\Storage\OmConflictException;
use App\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Port of OM ReflectGenerationJobHandler: delta Reflector then conditional bounded Dropper.
 * Routed to the `om_reflect` transport, which the OM consumer polls before `om`.
 */
#[AsMessageHandler]
final readonly class ReflectMemoryGenerationMessageHandler
{
    public function __construct(
        private MemorySessionRepository $sessions,
        private MemorySessionLock $sessionLock,
        private ReflectorPipeline $reflector,
        private DropperPipeline $dropper,
        private ObservationRepository $observationRepo,
        private MemoryGenerationRepository $generationRepo,
        private MemoryModelSelectionService $selections,
        private MemoryTokenLimits $tokenLimits,
        private ProjectIndexManager $projectIndexManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReflectMemoryGenerationMessage $message): ?MemorySessionWorkOutcome
    {
        $session = $this->sessions->find($message->sessionPk);
        if (null === $session) {
            return null;
        }
        $sessionPk = $session['session_pk'];
        $runId = $session['run_id'];
        $generationId = $message->generationId;
        $model = $message->reflectorModel;
        $reflectorSchemaVersion = $message->reflectorSchemaVersion;
        $sourceKind = MemorySourceKind::tryFrom($session['source_kind'])
            ?? throw new UnrecoverableMessageHandlingException('Unknown memory source kind.');
        // Limits are read per source kind when the job runs; the identity pins model and schema only.
        $settings = $this->tokenLimits->apply($this->resolveSettings($message), $sourceKind);

        $expectedId = OmIdentity::thresholdGenerationId(
            $runId,
            $message->priorActiveGenerationId,
            $message->observationSetHash,
            $model,
            $reflectorSchemaVersion,
        );
        if ($expectedId !== $generationId) {
            throw new UnrecoverableMessageHandlingException('reflect_generation generation_id does not match threshold formula.');
        }
        if ($reflectorSchemaVersion !== $settings->reflectorSchemaVersion) {
            throw new UnrecoverableMessageHandlingException('reflect_generation schema mismatch with pinned settings.');
        }

        $lock = $this->sessionLock->tryAcquire($sessionPk);
        if (null === $lock) {
            return MemorySessionWorkOutcome::SessionBusy;
        }

        try {
            $promoted = $this->reflect($message, $sessionPk, $runId, $settings);
        } finally {
            $lock->release();
        }

        if ($promoted) {
            $this->projectIndexManager->queueId($session['project_id']);
        }

        return null;
    }

    private function reflect(
        ReflectMemoryGenerationMessage $message,
        int $sessionPk,
        string $runId,
        PinnedMemorySettings $settings,
    ): bool {
        $generationId = $message->generationId;
        $observationSetHash = $message->observationSetHash;
        $model = $message->reflectorModel;
        $reflectorSchemaVersion = $message->reflectorSchemaVersion;

        $liveCandidateForClaim = $this->observationRepo->activeCandidateSet($sessionPk, $runId);
        $claimRequiredEndSeq = $liveCandidateForClaim['max_source_end_seq'];
        if ($claimRequiredEndSeq < 1 && $message->requiredEndSeq > 0) {
            $claimRequiredEndSeq = $message->requiredEndSeq;
        }

        $claim = $this->generationRepo->claimGeneration(
            sessionPk: $sessionPk,
            generationId: $generationId,
            observationSetHash: $observationSetHash,
            reflectorModel: $model,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            now: $this->nowUtc(),
            requiredStartSeq: $message->requiredStartSeq,
            requiredEndSeq: $claimRequiredEndSeq,
        );

        if (MemoryGenerationRepository::CLAIM_ALREADY_SUCCEEDED === $claim) {
            $this->logger->info('om.reflect.threshold_redelivery_noop', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_redelivery_noop',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'claim_status' => $claim,
            ]);

            return false;
        }
        if (MemoryGenerationRepository::CLAIM_CONFLICT === $claim) {
            // Deterministic: retrying cannot resolve an identity conflict.
            throw new UnrecoverableMessageHandlingException(\sprintf('Threshold generation claim conflict for %s.', $generationId));
        }

        try {
            $livePrior = $this->generationRepo->activeGenerationId($sessionPk);
            $candidate = $this->observationRepo->activeCandidateSet($sessionPk, $runId);
            $liveGenerationId = OmIdentity::thresholdGenerationId($runId, $livePrior, $candidate['observation_set_hash'], $model, $reflectorSchemaVersion);

            if ($candidate['token_count'] <= $settings->reflectAfterObservationTokens
                || [] === $candidate['observation_ids']) {
                $this->generationRepo->markSucceededNoop($sessionPk, $generationId, $this->nowUtc());
                $this->logger->info('om.reflect.threshold_noop', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_noop',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'token_count' => $candidate['token_count'],
                ]);

                return false;
            }

            if ($liveGenerationId !== $generationId || $candidate['observation_set_hash'] !== $observationSetHash) {
                $this->generationRepo->markFailed($sessionPk, $generationId, MemoryGenerationFailureCode::StaleObservationSet, $this->nowUtc());
                $this->logger->info('om.reflect.threshold_stale', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_stale',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'live_generation_id' => $liveGenerationId,
                ]);

                return false;
            }

            $delta = $this->reflector->produceDelta(
                sessionPk: $sessionPk,
                runId: $runId,
                settings: $settings,
                jobId: $generationId,
                correlationId: $runId,
            );

            $priorReflections = $delta['prior_reflections'];
            $newReflections = $delta['new_reflections'];
            $activeObservations = $delta['active_observations'];

            $droppedIds = [];
            $activeObsTokens = 0;
            foreach ($activeObservations as $observation) {
                $activeObsTokens += OmTokenEstimator::estimate($observation['content']);
            }

            // Dropper only after >=1 new reflection AND pool over observations_max_tokens.
            if (\count($newReflections) >= 1 && $activeObsTokens > $settings->observationsMaxTokens) {
                $droppedIds = $this->dropper->selectDrops(
                    runId: $runId,
                    reflectionsForCoverage: array_merge($priorReflections, $newReflections),
                    activeObservations: $activeObservations,
                    settings: $settings,
                    jobId: $generationId,
                    correlationId: $runId,
                );
            }

            $dropSet = array_fill_keys($droppedIds, true);
            $retainedObservationIds = [];
            foreach ($activeObservations as $observation) {
                if (!isset($dropSet[$observation['observation_id']])) {
                    $retainedObservationIds[] = $observation['observation_id'];
                }
            }

            // Re-check after both model stages: no transaction spans model calls.
            $postLivePrior = $this->generationRepo->activeGenerationId($sessionPk);
            $postCandidate = $this->observationRepo->activeCandidateSet($sessionPk, $runId);
            $postGenerationId = OmIdentity::thresholdGenerationId($runId, $postLivePrior, $postCandidate['observation_set_hash'], $model, $reflectorSchemaVersion);
            if ($postGenerationId !== $generationId || $postCandidate['observation_set_hash'] !== $observationSetHash) {
                $this->generationRepo->markFailed($sessionPk, $generationId, MemoryGenerationFailureCode::StaleObservationSet, $this->nowUtc());
                $this->logger->info('om.reflect.threshold_stale_after_models', [
                    'component' => 'observational_memory',
                    'event_type' => 'om.reflect.threshold_stale_after_models',
                    'run_id' => $runId,
                    'generation_id' => $generationId,
                    'live_generation_id' => $postGenerationId,
                ]);

                return false;
            }

            // Final generation = prior active reflections + new delta reflections.
            $finalReflections = array_merge($priorReflections, $newReflections);
            $this->generationRepo->commitSucceededGeneration(
                sessionPk: $sessionPk,
                generationId: $generationId,
                observationSetHash: $observationSetHash,
                reflectorModel: $model,
                reflectorSchemaVersion: $reflectorSchemaVersion,
                reflections: array_map(static fn (array $r): array => [
                    'reflection_id' => $r['reflection_id'],
                    'content' => $r['content'],
                    'supporting_observation_ids_json' => $r['supporting_observation_ids_json'],
                    'token_count' => $r['token_count'],
                ], $finalReflections),
                retainedObservationIds: $retainedObservationIds,
                now: $this->nowUtc(),
            );

            $this->logger->info('om.reflect.threshold_succeeded', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_succeeded',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'prior_reflection_count' => \count($priorReflections),
                'new_reflection_count' => \count($newReflections),
                'dropped_observation_count' => \count($droppedIds),
                'retained_observation_count' => \count($retainedObservationIds),
            ]);

            return true;
        } catch (ReflectorException $e) {
            $this->generationRepo->markFailed($sessionPk, $generationId, $e->failureCode, $this->nowUtc());
            $this->logger->error('om.reflect.threshold_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.reflect.threshold_failed',
                'run_id' => $runId,
                'generation_id' => $generationId,
                'failure_code' => $e->failureCode->value,
                'exception_class' => $e::class,
            ]);

            // Durable Reflector semantic failures are terminal for this generation claim.
            return false;
        } catch (OmConflictException $e) {
            $this->generationRepo->markFailed($sessionPk, $generationId, MemoryGenerationFailureCode::Conflict, $this->nowUtc());
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        } catch (\Throwable $e) {
            // Any other failure (provider, DBAL, ...) leaves the claim reclaimable on retry.
            $this->generationRepo->markFailed($sessionPk, $generationId, MemoryGenerationFailureCode::TransientRuntime, $this->nowUtc());
            throw $e;
        }
    }

    private function resolveSettings(ReflectMemoryGenerationMessage $message): PinnedMemorySettings
    {
        try {
            return $this->selections->resolvePinned(
                $message->presetId ?? null,
                $message->reasoningLevel ?? null,
                $message->contextWindow ?? null,
                $message->reflectorModel,
            );
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }

    private function nowUtc(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
