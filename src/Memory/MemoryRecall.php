<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Index\Source\MemoryKind;
use App\ObservationalMemory\Storage\MemoryGenerationRepository;
use App\ObservationalMemory\Storage\ObservationRepository;
use App\ObservationalMemory\Storage\SourceCheckpointRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Canonical OM recall against Librarian-owned memory tables plus session journals.
 *
 * Matches Hatfield OM recall payload/error semantics for observations and
 * reflections, including source_refs and client transcript evidence hydration (Hatfield events.jsonl or Claude JSONL).
 * Memory IDs are resolved project-wide; the stored run_id becomes the session
 * used for supporting observations and journal evidence.
 */
final class MemoryRecall
{
    private const string ID_PATTERN = '/^[a-f0-9]{12,64}$/';
    private const int AMBIGUITY_LIST_LIMIT = 50;
    private const int SUPPORT_CHUNK_SIZE = 400;
    private const int MAX_SUPPORT_IDS = 10_000;
    private const int MAX_SOURCE_REFS = 10_000;
    private const int DEFAULT_DEADLINE_SECONDS = 30;

    public function __construct(
        private readonly ObservationRepository $observations,
        private readonly MemoryGenerationRepository $generations,
        private readonly Connection $connection,
        private readonly MemorySourceReaders $sources,
        private readonly SourceCheckpointRepository $checkpoints,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function recall(
        Project $project,
        string $id,
        ?\DateTimeImmutable $deadline = null,
    ): array {
        $deadline ??= $this->clock->now()->modify('+'.self::DEFAULT_DEADLINE_SECONDS.' seconds');

        $id = strtolower(trim($id));
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return $this->error(MemoryErrorCode::InvalidId, 'id must be a lowercase hex string of 12 to 64 characters.');
        }

        if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before database open.'))) {
            return $interrupt;
        }

        $projectId = $project->id;
        if (null === $projectId) {
            return $this->error(MemoryErrorCode::SourceUnavailable, 'Registered project is not persisted.');
        }

        try {
            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before memory lookup.'))) {
                return $interrupt;
            }

            $obsMatches = $this->observations->findObservationsByIdPrefix($projectId, $id);
            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before reflection lookup.'))) {
                return $interrupt;
            }
            $refMatches = $this->generations->findReflectionsByIdPrefix($projectId, $id);

            $totalMatches = \count($obsMatches) + \count($refMatches);

            if (0 === $totalMatches) {
                return $this->error(MemoryErrorCode::NotFound, 'No observation or reflection with that id in the selected project. It may have been removed with a deleted session.');
            }

            if ($totalMatches > 1) {
                $candidates = [];
                foreach ($obsMatches as $observation) {
                    if (\count($candidates) >= self::AMBIGUITY_LIST_LIMIT) {
                        break;
                    }
                    $candidates[] = [
                        'id' => $observation['observation_id'],
                        'content' => $observation['content'],
                    ];
                }
                foreach ($refMatches as $reflection) {
                    if (\count($candidates) >= self::AMBIGUITY_LIST_LIMIT) {
                        break;
                    }
                    $candidates[] = [
                        'id' => $reflection['reflection_id'],
                        'content' => $reflection['content'],
                    ];
                }

                return $this->ambiguousId(
                    $candidates,
                    $totalMatches > self::AMBIGUITY_LIST_LIMIT,
                );
            }

            if (1 === \count($obsMatches)) {
                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before observation event hydration.'))) {
                    return $interrupt;
                }

                $observation = $obsMatches[0];
                $targetSessionPk = $observation['session_pk'];
                $targetRunId = $observation['run_id'];
                $targetSourceKind = $observation['source_kind'];
                $refs = $this->sourceRefsForRun(
                    $targetRunId,
                    $this->decodeSourceRefs($observation['source_refs_json']),
                );
                if (\count($refs) > self::MAX_SOURCE_REFS) {
                    return $this->error(MemoryErrorCode::ResourceLimitExceeded, 'Observation source refs exceeded the bounded recall limit.');
                }

                $pendingEvents = [
                    'kind' => MemoryKind::Observation,
                    'id' => $observation['observation_id'],
                    'content' => $observation['content'],
                    'timestamp' => $observation['timestamp'],
                    'importance' => $observation['relevance'],
                    'refs' => $refs,
                    'support_ids' => null,
                ];
            } else {
                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                    return $interrupt;
                }

                $reflection = $refMatches[0];
                $targetSessionPk = $reflection['session_pk'];
                $targetRunId = $reflection['run_id'];
                $targetSourceKind = $reflection['source_kind'];
                $requestedSupportIds = $this->decodeStringList($reflection['supporting_observation_ids_json']);
                if (\count($requestedSupportIds) > self::MAX_SUPPORT_IDS) {
                    return $this->error(MemoryErrorCode::ResourceLimitExceeded, 'Supporting observation ids exceeded the bounded recall limit.');
                }
                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                    return $interrupt;
                }

                $supportReferences = $this->supportReferences($reflection['session_pk'], $requestedSupportIds, $deadline);
                if ($this->isFailureResult($supportReferences)) {
                    return $supportReferences;
                }

                /** @var array<string, string> $supportReferences */
                $supportIds = $this->supportIdsForRun($supportReferences, $requestedSupportIds);
                $refs = [];
                foreach ($supportIds as $supportId) {
                    if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                        return $interrupt;
                    }
                    foreach ($this->sourceRefsForRun(
                        $targetRunId,
                        $this->decodeSourceRefs($supportReferences[$supportId]),
                    ) as $ref) {
                        $key = $ref['run_id'].':'.$ref['seq'];
                        $refs[$key] = $ref;
                        if (\count($refs) > self::MAX_SOURCE_REFS) {
                            return $this->error(MemoryErrorCode::ResourceLimitExceeded, 'Reflection source refs exceeded the bounded recall limit.');
                        }
                    }
                }
                $refs = array_values($refs);
                usort($refs, static function (array $a, array $b): int {
                    $byRun = strcmp($a['run_id'], $b['run_id']);
                    if (0 !== $byRun) {
                        return $byRun;
                    }

                    return $a['seq'] <=> $b['seq'];
                });

                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before reflection event hydration.'))) {
                    return $interrupt;
                }

                $pendingEvents = [
                    'kind' => MemoryKind::Reflection,
                    'id' => $reflection['reflection_id'],
                    'content' => $reflection['content'],
                    'timestamp' => null,
                    'importance' => null,
                    'refs' => $refs,
                    'support_ids' => $supportIds,
                ];
            }
        } catch (\PDOException|DbalException) {
            return $this->error(MemoryErrorCode::SourceUnavailable, 'Unable to read observational memory.');
        }

        $sourceKind = MemorySourceKind::tryFrom($targetSourceKind);
        $sourceRoot = null === $sourceKind ? null : $this->sources->sourceRoot($sourceKind, $project->rootCanonical, $project->sessionsPath);
        if (null === $sourceKind || null === $sourceRoot) {
            return $this->error(MemoryErrorCode::SessionsUnavailable, 'Registered project has no available session source for this memory.');
        }

        $events = $this->loadEventsForRefs($this->sources->reader($sourceKind), $sourceRoot, $targetSessionPk, $targetRunId, $pendingEvents['refs'], $deadline);
        if ($this->isFailureResult($events)) {
            return $events;
        }

        $result = [
            'ok' => true,
            'kind' => $pendingEvents['kind']->value,
            'session_id' => $targetRunId,
            'id' => $pendingEvents['id'],
            'content' => $pendingEvents['content'],
            'source_refs' => $pendingEvents['refs'],
            'events' => $events,
        ];
        if (MemoryKind::Observation === $pendingEvents['kind']) {
            $result['timestamp'] = $pendingEvents['timestamp'];
            $result['importance'] = $pendingEvents['importance'];
        } else {
            $result['supporting_observation_ids'] = $pendingEvents['support_ids'];
        }

        return $result;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    private function supportReferences(int $sessionPk, array $ids, \DateTimeImmutable $deadline): array
    {
        $references = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::SUPPORT_CHUNK_SIZE) as $page) {
            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                return $interrupt;
            }
            if ([] === $page) {
                continue;
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT observation_id, source_refs_json FROM om_observations
                 WHERE memory_session_id = ? AND observation_id IN (?)',
                [$sessionPk, $page],
                [ParameterType::INTEGER, ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $observationId = $row['observation_id'] ?? null;
                $sourceRefsJson = $row['source_refs_json'] ?? null;
                if (!\is_string($observationId) || '' === $observationId || !\is_string($sourceRefsJson)) {
                    throw new \PDOException('Supporting observation row is missing required columns.');
                }
                $references[$observationId] = $sourceRefsJson;
            }
        }

        return $references;
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    private function loadEventsForRefs(SessionSourceReader $reader, string $sourceRoot, int $sessionPk, string $currentRunId, array $refs, \DateTimeImmutable $deadline): array
    {
        if ([] === $refs) {
            return [];
        }

        /** @var list<int> $seqs */
        $seqs = [];
        foreach ($refs as $ref) {
            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while collecting event refs.'))) {
                return $interrupt;
            }
            $run = $ref['run_id'];
            $seq = $ref['seq'];
            if ($seq < 1 || $run !== $currentRunId) {
                continue;
            }
            $seqs[] = $seq;
        }

        if ([] === $seqs) {
            return [];
        }

        if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before reading session events.'))) {
            return $interrupt;
        }

        return $reader->loadForSession(
            $sourceRoot,
            $currentRunId,
            $seqs,
            function () use ($deadline): ?array {
                return $this->timeoutMap($deadline, 'Timed out while reading session events.');
            },
            $this->checkpoints->latestBefore($sessionPk, min($seqs)),
        );
    }

    /**
     * /**
     * @return list<array{run_id: string, seq: int}>
     */
    private function decodeSourceRefs(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \PDOException('Source refs JSON is corrupt.', 0, $exception);
        }
        if (!\is_array($decoded)) {
            throw new \PDOException('Source refs JSON has an unexpected shape.');
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                throw new \PDOException('Source refs JSON has an unexpected shape.');
            }
            $runId = isset($item['run_id']) && \is_string($item['run_id'])
                ? $item['run_id']
                : (isset($item['runId']) && \is_string($item['runId']) ? $item['runId'] : '');
            $seq = isset($item['seq']) && is_numeric($item['seq']) ? (int) $item['seq'] : 0;
            if ('' === $runId || $seq < 1) {
                throw new \PDOException('Source refs JSON has an unexpected shape.');
            }
            $out[] = ['run_id' => $runId, 'seq' => $seq];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \PDOException('Supporting observation ids JSON is corrupt.', 0, $exception);
        }
        if (!\is_array($decoded)) {
            throw new \PDOException('Supporting observation ids JSON has an unexpected shape.');
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!\is_string($item) || '' === $item) {
                throw new \PDOException('Supporting observation ids JSON has an unexpected shape.');
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int}>
     */
    private function sourceRefsForRun(string $runId, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if ($ref['run_id'] !== $runId) {
                continue;
            }
            $out[] = $ref;
        }

        return $out;
    }

    /**
     * @param array<string, string> $supportReferences
     * @param list<string>          $supportIds
     *
     * @return list<string>
     */
    private function supportIdsForRun(array $supportReferences, array $supportIds): array
    {
        $out = [];
        $seen = [];
        foreach ($supportIds as $supportId) {
            if (isset($seen[$supportId])) {
                continue;
            }
            $seen[$supportId] = true;
            if (!isset($supportReferences[$supportId])) {
                continue;
            }
            $out[] = $supportId;
        }

        return $out;
    }

    /**
     * @phpstan-impure Reading the clock can change the result between checkpoints.
     *
     * @return array{ok: false, error: string, message: string, timed_out: true}|null
     */
    private function timeoutMap(\DateTimeImmutable $deadline, string $message): ?array
    {
        if ($this->clock->now() < $deadline) {
            return null;
        }

        return [
            'ok' => false,
            'error' => MemoryErrorCode::Timeout->value,
            'timed_out' => true,
            'message' => $message,
        ];
    }

    /**
     * @param list<array{id: string, content: string}> $candidates
     *
     * @return array{ok: false, error: string, message: string, candidates: list<array{id: string, content: string}>, candidates_truncated: bool}
     */
    private function ambiguousId(array $candidates, bool $truncated): array
    {
        return [
            'ok' => false,
            'error' => MemoryErrorCode::AmbiguousId->value,
            'message' => 'Multiple memories match that id in the selected project. Retry with a full id from candidates.',
            'candidates' => $candidates,
            'candidates_truncated' => $truncated,
        ];
    }

    /**
     * @return array{ok: false, error: string, message: string}
     */
    private function error(MemoryErrorCode $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code->value,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isFailureResult(array $result): bool
    {
        return isset($result['ok']) && false === $result['ok'];
    }
}
