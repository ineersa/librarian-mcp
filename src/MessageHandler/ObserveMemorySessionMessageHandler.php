<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MemorySourceKind;
use App\Index\ProjectIndexManager;
use App\Memory\MemorySourceReaders;
use App\Message\ObserveMemorySessionMessage;
use App\Message\ReflectMemoryGenerationMessage;
use App\ObservationalMemory\MemorySessionLock;
use App\ObservationalMemory\MemorySessionWorkOutcome;
use App\ObservationalMemory\Model\MemoryModelSelectionService;
use App\ObservationalMemory\Model\MemoryTokenLimits;
use App\ObservationalMemory\Model\PinnedMemorySettings;
use App\ObservationalMemory\Observer\ObserverPipeline;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\MemorySessionRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use App\ObservationalMemory\Storage\OmConflictException;
use App\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Port of OM ObserveBoundaryJobHandler: observe through the client's end seq, then
 * maybe dispatch the threshold Reflector job once all chunks are durable.
 */
#[AsMessageHandler]
final readonly class ObserveMemorySessionMessageHandler
{
    public function __construct(
        private MemorySessionRepository $sessions,
        private MemorySourceReaders $sources,
        private MemorySessionLock $sessionLock,
        private ObserverPipeline $observer,
        private ObservationRepository $repository,
        private MemoryGenerationRepository $generationRepository,
        private MemoryModelSelectionService $selections,
        private MemoryTokenLimits $tokenLimits,
        private MessageBusInterface $bus,
        private ProjectIndexManager $projectIndexManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ObserveMemorySessionMessage $message): ?MemorySessionWorkOutcome
    {
        $session = $this->sessions->find($message->sessionPk);
        if (null === $session) {
            // Project removal cascades sessions; stale jobs are inert.
            return null;
        }
        $sourceKind = MemorySourceKind::tryFrom($session['source_kind'])
            ?? throw new UnrecoverableMessageHandlingException('Unknown memory source kind.');
        $sourceRoot = $this->sources->sourceRoot($sourceKind, $session['project_root'], $session['sessions_path']);
        if (null === $sourceRoot) {
            // Discovery has not resolved the Hatfield journal root yet, or the Claude transcript
            // directory is not mounted/created yet; retry later.
            throw new \RuntimeException('Session source root for this project is not available yet.');
        }

        $settings = $this->tokenLimits->apply($this->resolveSettings($message), $sourceKind);

        $lock = $this->sessionLock->tryAcquire($message->sessionPk);
        if (null === $lock) {
            return MemorySessionWorkOutcome::SessionBusy;
        }

        try {
            try {
                $result = $this->observer->observeThrough(
                    sessionPk: $session['session_pk'],
                    runId: $session['run_id'],
                    sourceReader: $this->sources->reader($sourceKind),
                    sourceRoot: $sourceRoot,
                    terminalEndSeq: $message->terminalEndSeq,
                    terminalStatus: $message->terminalStatus->value,
                    settings: $settings,
                    jobId: null,
                    correlationId: $session['run_id'],
                );
            } catch (OmConflictException $e) {
                // Coverage/observation identity conflicts cannot be fixed by retrying.
                throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
            }

            $this->maybeDispatchThresholdReflection($session['session_pk'], $session['run_id'], $settings);
        } finally {
            $lock->release();
        }

        if ($result['observation_count'] > 0) {
            $this->projectIndexManager->queueId($session['project_id']);
        }
        if ($result['more']) {
            // The per-job rendered-event budget stopped short of the requested end; continue from the new coverage.
            $this->bus->dispatch(new ObserveMemorySessionMessage(
                ...$settings->toMessagePayload(),
                sessionPk: $message->sessionPk,
                terminalEndSeq: $message->terminalEndSeq,
                terminalStatus: $message->terminalStatus,
            ));
        }

        return null;
    }

    private function maybeDispatchThresholdReflection(int $sessionPk, string $runId, PinnedMemorySettings $settings): void
    {
        if ($this->repository->activeCandidateStats($sessionPk)['token_count'] <= $settings->reflectAfterObservationTokens) {
            return;
        }
        $candidate = $this->repository->activeCandidateSet($sessionPk, $runId);
        if ($candidate['token_count'] <= $settings->reflectAfterObservationTokens) {
            return;
        }
        if ([] === $candidate['observation_ids']) {
            return;
        }
        // Suppress re-dispatch when this exact set already has running/succeeded/failed.
        if ($this->generationRepository->hasTerminalOrInFlightGenerationForSet($sessionPk, $candidate['observation_set_hash'])) {
            $this->logger->info('om.observe.threshold_suppressed', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.threshold_suppressed',
                'run_id' => $runId,
                'observation_set_hash' => $candidate['observation_set_hash'],
                'token_count' => $candidate['token_count'],
            ]);

            return;
        }

        $priorActive = $this->generationRepository->activeGenerationId($sessionPk);
        $generationId = OmIdentity::thresholdGenerationId(
            $runId,
            $priorActive,
            $candidate['observation_set_hash'],
            $settings->model,
            $settings->reflectorSchemaVersion,
        );

        $this->bus->dispatch(new ReflectMemoryGenerationMessage(
            sessionPk: $sessionPk,
            generationId: $generationId,
            observationSetHash: $candidate['observation_set_hash'],
            priorActiveGenerationId: $priorActive,
            reflectorModel: $settings->model,
            reflectorSchemaVersion: $settings->reflectorSchemaVersion,
            requiredStartSeq: 1,
            // Source watermark for the exact active candidate set claimed at dispatch.
            requiredEndSeq: $candidate['max_source_end_seq'],
            presetId: $settings->presetId,
            reasoningLevel: $settings->reasoningLevel->value,
            contextWindow: $settings->contextWindow,
        ));

        $this->logger->info('om.observe.threshold_dispatched', [
            'component' => 'observational_memory',
            'event_type' => 'om.observe.threshold_dispatched',
            'run_id' => $runId,
            'generation_id' => $generationId,
            'observation_set_hash' => $candidate['observation_set_hash'],
            'token_count' => $candidate['token_count'],
        ]);
    }

    private function resolveSettings(ObserveMemorySessionMessage $message): PinnedMemorySettings
    {
        try {
            return $this->selections->resolvePinned(
                $message->presetId ?? null,
                $message->reasoningLevel ?? null,
                $message->contextWindow ?? null,
                $message->model,
            );
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
    }
}
