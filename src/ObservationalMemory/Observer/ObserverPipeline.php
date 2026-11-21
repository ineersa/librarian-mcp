<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Observer;

use App\Memory\SessionSourceReader;
use App\ObservationalMemory\Agent\MemoryAgentRunner;
use App\ObservationalMemory\Agent\MemoryAgentTool;
use App\ObservationalMemory\MemorySettings;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use App\ObservationalMemory\Storage\SourceCheckpointRepository;
use App\ObservationalMemory\Support\OmCanonicalJson;
use App\ObservationalMemory\Support\OmIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Port of OM ObserverPipeline: reads the client's session transcript range read-only, builds
 * source blocks, packs, calls the model and persists coverage exactly as OM does.
 *
 * Chunks under the configured context-window ratio; multi-call accumulate; zero-obs coverage valid.
 */
final readonly class ObserverPipeline
{
    private const int MAX_TOOL_CALLS = 6;

    public function __construct(
        private MemoryAgentRunner $agentRunner,
        private ObservationRepository $repository,
        private MemoryGenerationRepository $generationRepository,
        private SourceCheckpointRepository $checkpoints,
        private MemorySettings $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Observe missing coverage through terminalEndSeq using deterministic chunks/parts.
     *
     * @return array{
     *   status: 'inserted'|'noop'|'already_covered',
     *   observation_count: int,
     *   chunks_processed: int,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   more: bool
     * }
     *
     * `more` is true when the per-job read budget stopped short of terminalEndSeq; the caller
     * continues in a follow-up job
     */
    public function observeThrough(
        int $sessionPk,
        string $runId,
        SessionSourceReader $sourceReader,
        string $sourceRoot,
        int $terminalEndSeq,
        string $terminalStatus,
        ?string $jobId,
        ?string $correlationId,
    ): array {
        if ($terminalEndSeq < 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid terminal end seq %d for run %s.', $terminalEndSeq, $runId));
        }

        $settings = $this->settings;
        $repository = $this->repository;
        $rendererVersion = $settings->rendererVersion;
        $observerSchemaVersion = $settings->observerSchemaVersion;

        $contiguousEnd = $repository->contiguousCoveredEndSeq($sessionPk, $rendererVersion, $observerSchemaVersion);
        $sourceStartSeq = null === $contiguousEnd ? 1 : $contiguousEnd + 1;
        if ($sourceStartSeq > $terminalEndSeq) {
            $this->logger->info('om.observe.already_covered_range', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.already_covered_range',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'source_end_seq' => $terminalEndSeq,
            ]);

            return [
                'status' => 'already_covered',
                'observation_count' => 0,
                'chunks_processed' => 0,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $terminalEndSeq,
                'more' => false,
            ];
        }

        $observerModel = $settings->model;
        $envelope = $settings->observerEnvelope();

        $systemPrompt = ObserverSystemPrompt::text();
        $toolSchemaText = $this->toolSchemaEstimateText();
        $fixedOverhead = OmTokenEstimator::estimate($systemPrompt) + OmTokenEstimator::estimate($toolSchemaText) + 32;

        $memoryReflections = $this->memoryReflectionLines($sessionPk);
        $memoryObservations = $this->memoryObservationLines($sessionPk);

        $range = $sourceReader->readRange(
            $sourceRoot,
            $runId,
            $sourceStartSeq,
            $terminalEndSeq,
            $this->checkpoints->latestBefore($sessionPk, $sourceStartSeq),
        );
        $events = $range->events;
        if (null === $range->lastSeq || (!$range->truncated && $range->lastSeq < $terminalEndSeq)) {
            // end_seq comes from the client; the journal holds nothing past coverage yet.
            $this->logger->info('om.observe.journal_behind_signal', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.journal_behind_signal',
                'run_id' => $runId,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $terminalEndSeq,
            ]);

            // Temporary: the client signalled a range that is not readable yet.
            // Fail so Messenger's bounded `om` retry can try again; never mark covered.
            throw new \RuntimeException(\sprintf('Session journal for %s is not yet readable through signalled sequence %d.', $runId, $terminalEndSeq));
        }
        // Never mark seqs the source does not hold yet, or that this job's read budget did not reach.
        $terminalEndSeq = min($terminalEndSeq, $range->lastSeq);

        $blocks = (new OmSourceBlockBuilder())->build($events, $range->carried);
        if ([] === $blocks) {
            // No content blocks — still advance coverage for the range with a synthetic empty part.
            $blocks = [[
                'run_id' => $runId,
                'seq' => $sourceStartSeq,
                'kind' => 'empty',
                'rendered_text' => \sprintf("[Source entry id: %s:%d]\n[empty content range %d..%d status=%s]", $runId, $sourceStartSeq, $sourceStartSeq, $terminalEndSeq, $terminalStatus),
                'source_refs' => [['run_id' => $runId, 'seq' => $sourceStartSeq]],
            ]];
        }

        $localTime = $this->clock->now()->format('Y-m-d H:i');
        $parts = (new OmChunkPacker())->pack(
            runId: $runId,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            blocks: $blocks,
            memoryReflections: $memoryReflections,
            memoryObservations: $memoryObservations,
            envelopeTokens: $envelope,
            localTimeFallback: $localTime,
            fixedOverheadTokens: $fixedOverhead,
        );
        // Finish all rendering/validation before the first model call, just as
        // the eager pipeline did. Only packed request payloads spill to disk;
        // sorting, delayed tool pairing and the frozen memory snapshot stay unchanged.
        // SplTempFileObject owns and removes its spill file on success or exception.
        $requests = new \SplTempFileObject(1024 * 1024);
        $lengths = [];
        $coverage = [];
        foreach ($parts as $part) {
            // Invocation-private arrays only. Preserve exact string bytes rather
            // than introduce JSON encoding constraints on previously direct text.
            $request = serialize([
                'source_refs' => $part['source_refs'],
                'user_message' => $part['user_message'],
            ]);
            $length = \strlen($request);
            if ($requests->fwrite($request) !== $length) {
                throw new \RuntimeException('Failed to spool prepared Observer request.');
            }
            $lengths[] = $length;
            unset($part['rendered_part'], $part['source_refs'], $part['user_message']);
            $coverage[] = $part;
        }
        // Exhaust and release the packer before model calls: its frame owns the
        // complete block array, and later parts may still fail validation.
        unset($parts, $part, $request, $events, $blocks, $memoryReflections, $memoryObservations);

        // Packer ranges come from renderable block seqs only. Expand coverage so the
        // canonical interval [sourceStartSeq, terminalEndSeq] has no holes while
        // leaving rendered text/source_refs/digests (and observation IDs) unchanged.
        $parts = $this->normalizeChunkCoverageRanges(
            $coverage,
            $runId,
            $sourceStartSeq,
            $terminalEndSeq,
            $rendererVersion,
            $observerSchemaVersion,
        );
        unset($coverage);
        $requests->rewind();

        $totalObservations = 0;
        $processed = 0;
        $firstStart = $sourceStartSeq;
        $lastEnd = $sourceStartSeq;

        foreach ($parts as $index => $part) {
            $request = $requests->fread($lengths[$index]);
            if (\strlen($request) !== $lengths[$index]) {
                throw new \RuntimeException('Failed to read prepared Observer request.');
            }
            /** @var array{source_refs: list<array{run_id: string, seq: int}>, user_message: string} $payload */
            $payload = unserialize($request, ['allowed_classes' => false]);
            $part += $payload;
            unset($request, $payload);
            if ($repository->hasCompatibleCoverage($sessionPk, $part['coverage_key'], $part['source_digest'], $part['part_digest'])) {
                ++$processed;
                $lastEnd = max($lastEnd, $part['source_end_seq']);
                continue;
            }

            $toolHandler = new RecordObservationsToolHandler(
                runId: $runId,
                observerSchemaVersion: $observerSchemaVersion,
                allowedSourceRefs: $part['source_refs'],
            );

            $this->agentRunner->run(
                sessionId: $runId,
                instructions: $systemPrompt,
                input: $part['user_message'],
                tools: [
                    new MemoryAgentTool(
                        name: 'record_observations',
                        description: 'Record timestamped, rated observations for the new conversation chunk. Call repeatedly with progress receipts until the chunk is covered; empty list / no call is valid when nothing is durable.',
                        parametersJsonSchema: $this->toolParametersSchema(),
                        handler: $toolHandler,
                    ),
                ],
                correlationId: $jobId ?? $correlationId,
                maxToolCalls: self::MAX_TOOL_CALLS,
            );

            // Zero observations and/or no tool call at all is successful coverage.
            $observations = $toolHandler->collected();
            $coveredAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
            $boundaryKey = $part['chunk_key'];
            $result = $repository->commitChunkPartCoverage(
                sessionPk: $sessionPk,
                coverageKey: $part['coverage_key'],
                boundaryKey: $boundaryKey,
                sourceStartSeq: $part['source_start_seq'],
                sourceEndSeq: $part['source_end_seq'],
                chunkKey: $part['chunk_key'],
                partIndex: $part['part_index'],
                partCount: $part['part_count'],
                sourceDigest: $part['source_digest'],
                partDigest: $part['part_digest'],
                rendererVersion: $rendererVersion,
                observerSchemaVersion: $observerSchemaVersion,
                observerModel: $observerModel,
                observations: $observations,
                coveredAt: $coveredAt,
            );

            $totalObservations += $result['observation_count'];
            ++$processed;
            $firstStart = min($firstStart, $part['source_start_seq']);
            $lastEnd = max($lastEnd, $part['source_end_seq']);

            $this->logger->info('om.observe.chunk_persisted', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.chunk_persisted',
                'run_id' => $runId,
                'job_id' => $jobId,
                'correlation_id' => $correlationId,
                'status' => $result['status'],
                'observation_count' => $result['observation_count'],
                'part_index' => $part['part_index'],
                'part_count' => $part['part_count'],
                'source_start_seq' => $part['source_start_seq'],
                'source_end_seq' => $part['source_end_seq'],
                'token_estimate' => $part['token_estimate'],
                'envelope_tokens' => $envelope,
            ]);
        }

        // Every part through terminalEndSeq is durable, so later reads may start past it.
        if (null !== $range->end && $range->end->seq === $terminalEndSeq) {
            $this->checkpoints->record($sessionPk, $range->end);
        }

        return [
            'status' => $processed > 0 ? 'inserted' : 'noop',
            'observation_count' => $totalObservations,
            'chunks_processed' => $processed,
            'source_start_seq' => $firstStart,
            'source_end_seq' => $lastEnd,
            'more' => $range->truncated,
        ];
    }

    /**
     * Expand packed chunk coverage ranges so they tile [sourceStartSeq, terminalEndSeq].
     *
     * Multipart same-seq chunks keep identical start/end on every part. Only identity
     * fields that hash the source range (chunk_key, coverage_key) are recomputed.
     *
     * @param list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   token_estimate: int
     * }> $parts
     *
     * @return list<array{
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   part_index: int,
     *   part_count: int,
     *   source_digest: string,
     *   part_digest: string,
     *   chunk_key: string,
     *   coverage_key: string,
     *   token_estimate: int
     * }>
     */
    private function normalizeChunkCoverageRanges(
        array $parts,
        string $runId,
        int $sourceStartSeq,
        int $terminalEndSeq,
        string $rendererVersion,
        string $observerSchemaVersion,
    ): array {
        if ([] === $parts) {
            return $parts;
        }

        // Group consecutive parts that form one logical chunk (same original range + part_count).
        /** @var list<array{start: int, end: int, part_count: int, indexes: list<int>}> $groups */
        $groups = [];
        foreach ($parts as $index => $part) {
            $start = (int) $part['source_start_seq'];
            $end = (int) $part['source_end_seq'];
            $partCount = (int) $part['part_count'];
            $last = [] === $groups ? null : $groups[array_key_last($groups)];
            if (null !== $last && $last['start'] === $start && $last['end'] === $end && $last['part_count'] === $partCount) {
                $groups[array_key_last($groups)]['indexes'][] = $index;
                continue;
            }
            $groups[] = [
                'start' => $start,
                'end' => $end,
                'part_count' => $partCount,
                'indexes' => [$index],
            ];
        }

        // Leading non-renderable seqs attach to the first chunk.
        $groups[0]['start'] = min($groups[0]['start'], $sourceStartSeq);

        // Internal holes between chunks attach to the next chunk (backward expand start).
        // Packer groups are monotonic (start/end non-decreasing across groups), so expanding
        // the next start down to previous_end+1 cannot invert a range.
        $groupCount = \count($groups);
        for ($i = 0; $i < $groupCount - 1; ++$i) {
            $expectedNext = $groups[$i]['end'] + 1;
            if ($groups[$i + 1]['start'] > $expectedNext) {
                $groups[$i + 1]['start'] = $expectedNext;
            }
        }

        // Trailing non-renderable seqs attach to the last chunk.
        $lastIdx = $groupCount - 1;
        $groups[$lastIdx]['end'] = max($groups[$lastIdx]['end'], $terminalEndSeq);

        foreach ($groups as $group) {
            $chunkKey = OmIdentity::chunkKey(
                $runId,
                $group['start'],
                $group['end'],
                $rendererVersion,
                $observerSchemaVersion,
                $parts[$group['indexes'][0]]['source_digest'],
                $group['part_count'],
            );
            foreach ($group['indexes'] as $partIndex) {
                $parts[$partIndex]['source_start_seq'] = $group['start'];
                $parts[$partIndex]['source_end_seq'] = $group['end'];
                $parts[$partIndex]['chunk_key'] = $chunkKey;
                $parts[$partIndex]['coverage_key'] = OmIdentity::coverageKey(
                    $chunkKey,
                    $parts[$partIndex]['part_index'],
                    $parts[$partIndex]['part_digest'],
                );
            }
        }

        return $parts;
    }

    /**
     * @return list<array{id: string, line: string, tokens: int}>
     */
    private function memoryReflectionLines(int $sessionPk): array
    {
        $out = [];
        foreach ($this->generationRepository->listActiveReflections($sessionPk) as $reflection) {
            $line = \sprintf('[%s] %s', $reflection['reflection_id'], $reflection['content']);
            $out[] = [
                'id' => $reflection['reflection_id'],
                'line' => $line,
                'tokens' => OmTokenEstimator::estimate($line),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, line: string, tokens: int, timestamp: string, relevance: string}>
     */
    private function memoryObservationLines(int $sessionPk): array
    {
        $out = [];
        foreach ($this->repository->listActiveCandidateObservations($sessionPk) as $observation) {
            $line = \sprintf(
                '[%s] %s [%s] %s',
                $observation['observation_id'],
                $observation['timestamp'],
                $observation['relevance'],
                $observation['content'],
            );
            $out[] = [
                'id' => $observation['observation_id'],
                'line' => $line,
                'tokens' => OmTokenEstimator::estimate($line),
                'timestamp' => $observation['timestamp'],
                'relevance' => $observation['relevance'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolParametersSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['observations'],
            'properties' => [
                'observations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['timestamp', 'content', 'relevance', 'source_refs'],
                        'properties' => [
                            'timestamp' => [
                                'type' => 'string',
                                'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$',
                            ],
                            'content' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'description' => 'Single-line plain prose. No markdown/tags/embedded timestamp.',
                            ],
                            'relevance' => [
                                'type' => 'string',
                                'enum' => ['low', 'medium', 'high', 'critical'],
                            ],
                            'source_refs' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['run_id', 'seq'],
                                    'properties' => [
                                        'run_id' => ['type' => 'string', 'minLength' => 1],
                                        'seq' => ['type' => 'integer', 'minimum' => 1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function toolSchemaEstimateText(): string
    {
        return OmCanonicalJson::encode($this->toolParametersSchema());
    }
}
