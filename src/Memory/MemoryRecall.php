<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\Project;
use App\Index\Source\MemoryKind;
use App\Project\OmSourceReader;
use App\Project\ProjectSourceException;
use Psr\Clock\ClockInterface;

/**
 * Canonical read-only OM recall against a registered project's discovered sources.
 *
 * Matches Hatfield OM recall payload/error semantics for observations and
 * reflections, including source_refs and events.jsonl evidence hydration.
 * Memory IDs are resolved project-wide; the stored run_id becomes the session
 * used for supporting observations and journal evidence.
 */
final class MemoryRecall
{
    private const string ID_PATTERN = '/^[a-f0-9]{12,64}$/';
    private const int PREFIX_LOOKUP_LIMIT = 51;
    private const int AMBIGUITY_LIST_LIMIT = 50;
    private const int SUPPORT_CHUNK_SIZE = 400;
    private const int MAX_SUPPORT_IDS = 10_000;
    private const int MAX_SOURCE_REFS = 10_000;
    private const int DEFAULT_DEADLINE_SECONDS = 30;

    public function __construct(
        private readonly OmSourceReader $omSourceReader,
        private readonly SessionEventsJournalReader $journalReader,
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

        $databasePath = $project->databasePath;
        $sessionsPath = $project->sessionsPath;
        if (!\is_string($databasePath) || '' === $databasePath) {
            return $this->error(MemoryErrorCode::SourceUnavailable, 'Registered project has no discovered observational memory database.');
        }
        if (!\is_string($sessionsPath) || '' === $sessionsPath) {
            return $this->error(MemoryErrorCode::SessionsUnavailable, 'Registered project has no discovered session journal root.');
        }

        try {
            $pdo = $this->omSourceReader->openReadonlyConnection($databasePath);
        } catch (ProjectSourceException $exception) {
            return $this->error(MemoryErrorCode::SourceUnavailable, $exception->getMessage());
        }

        try {
            $pdo->beginTransaction();
            try {
                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before memory lookup.'))) {
                    return $interrupt;
                }

                try {
                    $this->omSourceReader->validateSchema($pdo);
                } catch (ProjectSourceException $exception) {
                    return $this->error(MemoryErrorCode::SourceUnavailable, $exception->getMessage());
                }

                $obsMatches = $this->findObservationsByIdPrefix($pdo, $id);
                if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out before reflection lookup.'))) {
                    return $interrupt;
                }
                $refMatches = $this->findReflectionsByIdPrefix($pdo, $id);

                $totalMatches = \count($obsMatches) + \count($refMatches);

                if (0 === $totalMatches) {
                    return $this->error(MemoryErrorCode::NotFound, 'No observation or reflection with that id in the selected project.');
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
                    $targetRunId = $observation['run_id'];
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
                    $targetRunId = $reflection['run_id'];
                    $requestedSupportIds = $this->decodeStringList($reflection['supporting_observation_ids_json']);
                    if (\count($requestedSupportIds) > self::MAX_SUPPORT_IDS) {
                        return $this->error(MemoryErrorCode::ResourceLimitExceeded, 'Supporting observation ids exceeded the bounded recall limit.');
                    }
                    if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                        return $interrupt;
                    }

                    $supportReferences = $this->supportReferences($pdo, $targetRunId, $requestedSupportIds, $deadline);
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
            } catch (\PDOException) {
                return $this->error(MemoryErrorCode::SourceUnavailable, 'Unable to read observational memory database snapshot.');
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            $events = $this->loadEventsForRefs($sessionsPath, $targetRunId, $pendingEvents['refs'], $deadline);
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
        } finally {
            $pdo = null;
        }
    }

    /**
     * @return list<array{
     *   observation_id: string,
     *   run_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   source_refs_json: string
     * }>
     */
    private function findObservationsByIdPrefix(\PDO $pdo, string $idPrefix): array
    {
        if (64 === \strlen($idPrefix)) {
            $statement = $pdo->prepare(
                'SELECT observation_id, run_id, content, relevance, timestamp, source_refs_json
                 FROM om_observation
                 WHERE observation_id = :observation_id
                 LIMIT 1',
            );
            $statement->bindValue(':observation_id', $idPrefix);
            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (false === $row) {
                return [];
            }
            if (!\is_array($row)) {
                throw new \PDOException('Unexpected observation row shape.');
            }

            return [$this->mapObservationRow($row)];
        }

        $statement = $pdo->prepare(
            'SELECT observation_id, run_id, content, relevance, timestamp, source_refs_json
             FROM om_observation
             WHERE observation_id LIKE :id_prefix
             ORDER BY observation_id ASC
             LIMIT :limit',
        );
        $statement->bindValue(':id_prefix', $idPrefix.'%');
        $statement->bindValue(':limit', self::PREFIX_LOOKUP_LIMIT, \PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!\is_array($row)) {
                throw new \PDOException('Unexpected observation row shape.');
            }
            $out[] = $this->mapObservationRow($row);
        }

        return $out;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   run_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string
     * }>
     */
    private function findReflectionsByIdPrefix(\PDO $pdo, string $idPrefix): array
    {
        if (64 === \strlen($idPrefix)) {
            $statement = $pdo->prepare(
                'SELECT reflection_id, run_id, content, supporting_observation_ids_json
                 FROM om_reflection
                 WHERE reflection_id = :reflection_id
                 LIMIT 1',
            );
            $statement->bindValue(':reflection_id', $idPrefix);
            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if (false === $row) {
                return [];
            }
            if (!\is_array($row)) {
                throw new \PDOException('Unexpected reflection row shape.');
            }

            return [$this->mapReflectionRow($row)];
        }

        $statement = $pdo->prepare(
            'SELECT reflection_id, run_id, content, supporting_observation_ids_json
             FROM om_reflection
             WHERE reflection_id LIKE :id_prefix
             ORDER BY reflection_id ASC
             LIMIT :limit',
        );
        $statement->bindValue(':id_prefix', $idPrefix.'%');
        $statement->bindValue(':limit', self::PREFIX_LOOKUP_LIMIT, \PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!\is_array($row)) {
                throw new \PDOException('Unexpected reflection row shape.');
            }
            $out[] = $this->mapReflectionRow($row);
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    private function supportReferences(\PDO $pdo, string $runId, array $ids, \DateTimeImmutable $deadline): array
    {
        $references = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::SUPPORT_CHUNK_SIZE) as $page) {
            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
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
                if (!\is_array($row)) {
                    throw new \PDOException('Unexpected supporting observation row shape.');
                }
                $observationId = $row['observation_id'] ?? null;
                $sourceRefsJson = $row['source_refs_json'] ?? null;
                if (!\is_string($observationId) || '' === $observationId || !\is_string($sourceRefsJson)) {
                    throw new \PDOException('Supporting observation row is missing required columns.');
                }
                $references[$observationId] = $sourceRefsJson;
            }

            if (null !== ($interrupt = $this->timeoutMap($deadline, 'Timed out while hydrating supporting observations.'))) {
                return $interrupt;
            }
        }

        return $references;
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    private function loadEventsForRefs(string $sessionsPath, string $currentRunId, array $refs, \DateTimeImmutable $deadline): array
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

        return $this->journalReader->loadForSession(
            $sessionsPath,
            $currentRunId,
            $seqs,
            function () use ($deadline): ?array {
                return $this->timeoutMap($deadline, 'Timed out while reading session events.');
            },
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   observation_id: string,
     *   run_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   source_refs_json: string
     * }
     */
    private function mapObservationRow(array $row): array
    {
        $observationId = $row['observation_id'] ?? null;
        $runId = $row['run_id'] ?? null;
        $content = $row['content'] ?? null;
        $relevance = $row['relevance'] ?? null;
        $timestamp = $row['timestamp'] ?? null;
        $sourceRefsJson = $row['source_refs_json'] ?? null;
        if (
            !\is_string($observationId) || '' === $observationId
            || !\is_string($runId) || '' === $runId
            || !\is_string($content)
            || !\is_string($relevance) || '' === $relevance
            || !\is_string($timestamp) || '' === $timestamp
            || !\is_string($sourceRefsJson)
        ) {
            throw new \PDOException('Observation row is missing required columns.');
        }

        return [
            'observation_id' => $observationId,
            'run_id' => $runId,
            'content' => $content,
            'relevance' => $relevance,
            'timestamp' => $timestamp,
            'source_refs_json' => $sourceRefsJson,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   reflection_id: string,
     *   run_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string
     * }
     */
    private function mapReflectionRow(array $row): array
    {
        $reflectionId = $row['reflection_id'] ?? null;
        $runId = $row['run_id'] ?? null;
        $content = $row['content'] ?? null;
        $supporting = $row['supporting_observation_ids_json'] ?? null;
        if (
            !\is_string($reflectionId) || '' === $reflectionId
            || !\is_string($runId) || '' === $runId
            || !\is_string($content)
            || !\is_string($supporting)
        ) {
            throw new \PDOException('Reflection row is missing required columns.');
        }

        return [
            'reflection_id' => $reflectionId,
            'run_id' => $runId,
            'content' => $content,
            'supporting_observation_ids_json' => $supporting,
        ];
    }

    /**
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
