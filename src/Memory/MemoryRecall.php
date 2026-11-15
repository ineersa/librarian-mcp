<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\Project;
use App\Project\OmSourceReader;
use App\Project\ProjectSourceException;

/**
 * Canonical read-only OM recall against a registered project's discovered sources.
 *
 * Matches Hatfield OM recall payload/error semantics for observations and
 * reflections, including source_refs and events.jsonl evidence hydration.
 */
final class MemoryRecall
{
    private const string ID_PATTERN = '/^[a-f0-9]{12,64}$/';
    private const int PREFIX_LOOKUP_LIMIT = 3;
    private const int SUPPORT_CHUNK_SIZE = 400;

    public function __construct(
        private readonly OmSourceReader $omSourceReader,
        private readonly SessionEventsJournalReader $journalReader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function recall(
        Project $project,
        string $currentSession,
        string $id,
        ?string $sessionId = null,
        ?int $deadlineNs = null,
    ): array {
        $id = strtolower(trim($id));
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return [
                'ok' => false,
                'error' => 'invalid_id',
                'message' => 'id must be a lowercase hex string of 12 to 64 characters.',
            ];
        }

        $targetRunId = trim($currentSession);
        if (null !== $sessionId) {
            $sessionId = trim($sessionId);
            if ('' === $sessionId) {
                return [
                    'ok' => false,
                    'error' => 'invalid_session_id',
                    'message' => 'session_id must be a non-empty session/run id when provided.',
                ];
            }
            $targetRunId = $sessionId;
        }

        if ('' === $targetRunId) {
            return [
                'ok' => false,
                'error' => 'invalid_session_id',
                'message' => 'session_id must be a non-empty session/run id when provided.',
            ];
        }

        if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before database open.'))) {
            return $interrupt;
        }

        $databasePath = $project->databasePath;
        $sessionsPath = $project->sessionsPath;
        if (!\is_string($databasePath) || '' === $databasePath) {
            return [
                'ok' => false,
                'error' => 'source_unavailable',
                'message' => 'Registered project has no discovered observational memory database.',
            ];
        }
        if (!\is_string($sessionsPath) || '' === $sessionsPath) {
            return [
                'ok' => false,
                'error' => 'sessions_unavailable',
                'message' => 'Registered project has no discovered session journal root.',
            ];
        }

        try {
            $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);
        } catch (ProjectSourceException $exception) {
            return [
                'ok' => false,
                'error' => 'source_unavailable',
                'message' => $exception->getMessage(),
            ];
        }

        try {
            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before observation lookup.'))) {
                return $interrupt;
            }

            try {
                $this->omSourceReader->validateSchema($pdo);
            } catch (ProjectSourceException $exception) {
                return [
                    'ok' => false,
                    'error' => 'source_unavailable',
                    'message' => $exception->getMessage(),
                ];
            }

            $obsMatches = $this->findObservationsByIdPrefix($pdo, $targetRunId, $id);
            if (\count($obsMatches) > 1) {
                return [
                    'ok' => false,
                    'error' => 'ambiguous_id',
                    'message' => 'Multiple observations match that id prefix in the selected session.',
                ];
            }

            if (1 === \count($obsMatches)) {
                if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before observation event hydration.'))) {
                    return $interrupt;
                }

                $observation = $obsMatches[0];
                $refs = $this->sourceRefsForRun(
                    $targetRunId,
                    $this->decodeSourceRefs($observation['source_refs_json']),
                );
                $events = $this->loadEventsForRefs($sessionsPath, $targetRunId, $refs, $deadlineNs);
                if ($this->isInterruptResult($events)) {
                    return $events;
                }
                if (isset($events['ok']) && false === $events['ok']) {
                    return $events;
                }

                return [
                    'ok' => true,
                    'kind' => 'observation',
                    'session_id' => $targetRunId,
                    'id' => $observation['observation_id'],
                    'content' => $observation['content'],
                    'timestamp' => $observation['timestamp'],
                    'importance' => $observation['relevance'],
                    'source_refs' => $refs,
                    'events' => $events,
                ];
            }

            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before reflection lookup.'))) {
                return $interrupt;
            }

            $refMatches = $this->findReflectionsByIdPrefix($pdo, $targetRunId, $id);
            if (\count($refMatches) > 1) {
                return [
                    'ok' => false,
                    'error' => 'ambiguous_id',
                    'message' => 'Multiple reflections match that id prefix in the selected session.',
                ];
            }
            if ([] === $refMatches) {
                return [
                    'ok' => false,
                    'error' => 'not_found',
                    'message' => \sprintf(
                        'No observation or reflection with that id in session %s. If this id came from memory_search, pass its session_id.',
                        $targetRunId,
                    ),
                ];
            }

            $reflection = $refMatches[0];
            $requestedSupportIds = $this->decodeStringList($reflection['supporting_observation_ids_json']);
            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled while hydrating supporting observations.'))) {
                return $interrupt;
            }

            $supportReferences = $this->supportReferences($pdo, $targetRunId, $requestedSupportIds, $deadlineNs);
            if ($this->isInterruptResult($supportReferences)) {
                return $supportReferences;
            }

            /** @var array<string, string> $supportReferences */
            $supportIds = $this->supportIdsForRun($supportReferences, $requestedSupportIds);
            $refs = [];
            foreach ($supportIds as $supportId) {
                if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled while hydrating supporting observations.'))) {
                    return $interrupt;
                }
                foreach ($this->sourceRefsForRun(
                    $targetRunId,
                    $this->decodeSourceRefs($supportReferences[$supportId]),
                ) as $ref) {
                    $key = $ref['run_id'].':'.$ref['seq'];
                    $refs[$key] = $ref;
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

            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before reflection event hydration.'))) {
                return $interrupt;
            }

            $events = $this->loadEventsForRefs($sessionsPath, $targetRunId, $refs, $deadlineNs);
            if ($this->isInterruptResult($events)) {
                return $events;
            }
            if (isset($events['ok']) && false === $events['ok']) {
                return $events;
            }

            return [
                'ok' => true,
                'kind' => 'reflection',
                'session_id' => $targetRunId,
                'id' => $reflection['reflection_id'],
                'content' => $reflection['content'],
                'supporting_observation_ids' => $supportIds,
                'source_refs' => $refs,
                'events' => $events,
            ];
        } catch (\PDOException) {
            return [
                'ok' => false,
                'error' => 'source_unavailable',
                'message' => 'Unable to read observational memory database snapshot.',
            ];
        } finally {
            $pdo = null;
        }
    }

    /**
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   source_refs_json: string
     * }>
     */
    private function findObservationsByIdPrefix(\PDO $pdo, string $runId, string $idPrefix): array
    {
        if (64 === \strlen($idPrefix)) {
            $statement = $pdo->prepare(
                'SELECT observation_id, content, relevance, timestamp, source_refs_json
                 FROM om_observation
                 WHERE run_id = :run_id AND observation_id = :observation_id
                 LIMIT 1',
            );
            $statement->bindValue(':run_id', $runId);
            $statement->bindValue(':observation_id', $idPrefix);
            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($row)) {
                return [];
            }

            return [$this->mapObservationRow($row)];
        }

        $statement = $pdo->prepare(
            'SELECT observation_id, content, relevance, timestamp, source_refs_json
             FROM om_observation
             WHERE run_id = :run_id AND observation_id LIKE :id_prefix
             ORDER BY observation_id ASC
             LIMIT :limit',
        );
        $statement->bindValue(':run_id', $runId);
        $statement->bindValue(':id_prefix', $idPrefix.'%');
        $statement->bindValue(':limit', self::PREFIX_LOOKUP_LIMIT, \PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->mapObservationRow($row);
        }

        return $out;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string
     * }>
     */
    private function findReflectionsByIdPrefix(\PDO $pdo, string $runId, string $idPrefix): array
    {
        if (64 === \strlen($idPrefix)) {
            $statement = $pdo->prepare(
                'SELECT reflection_id, content, supporting_observation_ids_json
                 FROM om_reflection
                 WHERE run_id = :run_id AND reflection_id = :reflection_id
                 LIMIT 1',
            );
            $statement->bindValue(':run_id', $runId);
            $statement->bindValue(':reflection_id', $idPrefix);
            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($row)) {
                return [];
            }

            return [$this->mapReflectionRow($row)];
        }

        $statement = $pdo->prepare(
            'SELECT reflection_id, content, supporting_observation_ids_json
             FROM om_reflection
             WHERE run_id = :run_id AND reflection_id LIKE :id_prefix
             ORDER BY reflection_id ASC
             LIMIT :limit',
        );
        $statement->bindValue(':run_id', $runId);
        $statement->bindValue(':id_prefix', $idPrefix.'%');
        $statement->bindValue(':limit', self::PREFIX_LOOKUP_LIMIT, \PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->mapReflectionRow($row);
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function supportReferences(\PDO $pdo, string $runId, array $ids, ?int $deadlineNs): array
    {
        $references = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::SUPPORT_CHUNK_SIZE) as $page) {
            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled while hydrating supporting observations.'))) {
                return $interrupt;
            }
            if ([] === $page) {
                continue;
            }

            $placeholders = [];
            $params = ['run_id' => $runId];
            foreach ($page as $index => $supportId) {
                $name = 'id_'.$index;
                $placeholders[] = ':'.$name;
                $params[$name] = $supportId;
            }

            $sql = \sprintf(
                'SELECT observation_id, source_refs_json
                 FROM om_observation
                 WHERE run_id = :run_id AND observation_id IN (%s)',
                implode(', ', $placeholders),
            );
            $statement = $pdo->prepare($sql);
            foreach ($params as $name => $value) {
                $statement->bindValue(':'.$name, $value);
            }
            $statement->execute();

            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $observationId = $row['observation_id'] ?? null;
                $sourceRefsJson = $row['source_refs_json'] ?? null;
                if (!\is_string($observationId) || '' === $observationId || !\is_string($sourceRefsJson)) {
                    continue;
                }
                $references[$observationId] = $sourceRefsJson;
            }

            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled while hydrating supporting observations.'))) {
                return $interrupt;
            }
        }

        return $references;
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string}|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
     */
    private function loadEventsForRefs(string $sessionsPath, string $currentRunId, array $refs, ?int $deadlineNs): array
    {
        if ([] === $refs) {
            return [];
        }

        /** @var list<int> $seqs */
        $seqs = [];
        foreach ($refs as $ref) {
            if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled while collecting event refs.'))) {
                return $interrupt;
            }
            $run = (string) $ref['run_id'];
            $seq = (int) $ref['seq'];
            if ($seq < 1 || $run !== $currentRunId) {
                continue;
            }
            $seqs[] = $seq;
        }

        if ([] === $seqs) {
            return [];
        }

        if (null !== ($interrupt = $this->interruptMap($deadlineNs, 'Cancelled before reading session events.'))) {
            return $interrupt;
        }

        return $this->journalReader->loadForSession(
            $sessionsPath,
            $currentRunId,
            $seqs,
            function () use ($deadlineNs): ?array {
                return $this->interruptMap($deadlineNs, 'Cancelled while reading session events.');
            },
        );
    }

    /**
     * @param array{observation_id?: mixed, content?: mixed, relevance?: mixed, timestamp?: mixed, source_refs_json?: mixed} $row
     *
     * @return array{
     *   observation_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   source_refs_json: string
     * }
     */
    private function mapObservationRow(array $row): array
    {
        return [
            'observation_id' => (string) ($row['observation_id'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'relevance' => (string) ($row['relevance'] ?? ''),
            'timestamp' => (string) ($row['timestamp'] ?? ''),
            'source_refs_json' => (string) ($row['source_refs_json'] ?? '[]'),
        ];
    }

    /**
     * @param array{reflection_id?: mixed, content?: mixed, supporting_observation_ids_json?: mixed} $row
     *
     * @return array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string
     * }
     */
    private function mapReflectionRow(array $row): array
    {
        return [
            'reflection_id' => (string) ($row['reflection_id'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'supporting_observation_ids_json' => (string) ($row['supporting_observation_ids_json'] ?? '[]'),
        ];
    }

    /**
     * @return list<array{run_id: string, seq: int}>
     */
    private function decodeSourceRefs(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $runId = isset($item['run_id']) && \is_string($item['run_id'])
                ? $item['run_id']
                : (isset($item['runId']) && \is_string($item['runId']) ? $item['runId'] : '');
            $seq = isset($item['seq']) && is_numeric($item['seq']) ? (int) $item['seq'] : 0;
            if ('' === $runId || $seq < 1) {
                continue;
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
        } catch (\JsonException) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (\is_string($item) && '' !== $item) {
                $out[] = $item;
            }
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
     * @return array{timed_out: true, timeout_seconds: int, message: string}|null
     */
    private function interruptMap(?int $deadlineNs, string $message): ?array
    {
        if (null === $deadlineNs) {
            return null;
        }

        // OM deadlineNs is hrtime(true)-based; keep that comparison semantics.
        if (hrtime(true) < $deadlineNs) {
            return null;
        }

        return [
            'timed_out' => true,
            'timeout_seconds' => 0,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isInterruptResult(array $result): bool
    {
        return isset($result['cancelled']) || isset($result['timed_out']);
    }
}
