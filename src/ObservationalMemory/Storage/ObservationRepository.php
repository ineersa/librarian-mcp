<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\Entity\MemoryGenerationStatus;
use App\ObservationalMemory\Observer\OmTokenEstimator;
use App\ObservationalMemory\Support\OmIdentity;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Port of OM ObservationRepository onto Librarian PostgreSQL.
 *
 * Rows are scoped by `om_sessions.id` instead of the OM `run_id` column. The run id
 * itself stays an identity-hash input owned by callers.
 */
final readonly class ObservationRepository
{
    public const string PAGE_QUERY = 'observations_page';
    public const int PAGE_SIZE = 20;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function hasCompatibleCoverage(int $sessionPk, string $coverageKey, string $sourceDigest, string $partDigest): bool
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT source_digest, part_digest FROM om_observer_coverage WHERE memory_session_id = ? AND coverage_key = ?',
            [$sessionPk, $coverageKey],
        );

        if (false === $existing) {
            return false;
        }

        if (($existing['source_digest'] ?? '') !== $sourceDigest
            || ($existing['part_digest'] ?? '') !== $partDigest) {
            throw new OmConflictException(\sprintf('Coverage conflict for key %s: digest mismatch.', $coverageKey));
        }

        return true;
    }

    /**
     * Contiguous covered end seq from source seq 1 under active renderer/schema versions.
     *
     * Complete chunks only; never MAX(source_end_seq).
     */
    public function contiguousCoveredEndSeq(int $sessionPk, string $rendererVersion, string $observerSchemaVersion): ?int
    {
        $intervals = $this->completeCoverageChunks([$sessionPk], $rendererVersion, $observerSchemaVersion);

        $expected = 1;
        foreach ($intervals as $interval) {
            $start = (int) $interval['source_start_seq'];
            $end = (int) $interval['source_end_seq'];
            if ($end < $expected) {
                continue;
            }
            if ($start > $expected) {
                break;
            }
            $expected = max($expected, $end + 1);
        }

        return 1 === $expected ? null : $expected - 1;
    }

    /**
     * @param non-empty-list<int> $sessionPks
     *
     * @return iterable<array<string, mixed>>
     */
    public function completeCoverageChunks(array $sessionPks, string $rendererVersion, string $observerSchemaVersion): iterable
    {
        return $this->connection->iterateAssociative(
            'SELECT memory_session_id, chunk_key, MIN(source_start_seq) AS source_start_seq, MIN(source_end_seq) AS source_end_seq
             FROM om_observer_coverage
             WHERE memory_session_id IN (?) AND renderer_version = ? AND observer_schema_version = ?
               AND chunk_key <> \'\'
             GROUP BY memory_session_id, chunk_key
             HAVING MIN(part_count) >= 1
                AND MIN(part_count) = MAX(part_count)
                AND MIN(source_start_seq) >= 1
                AND MIN(source_start_seq) = MAX(source_start_seq)
                AND MIN(source_end_seq) >= MIN(source_start_seq)
                AND MIN(source_end_seq) = MAX(source_end_seq)
                AND MIN(source_digest) <> \'\' AND MIN(source_digest) = MAX(source_digest)
                AND MIN(part_index) = 1
                AND MAX(part_index) = MIN(part_count)
                AND COUNT(DISTINCT part_index) = MIN(part_count)
             ORDER BY memory_session_id, source_start_seq ASC, source_end_seq DESC, chunk_key ASC',
            [$sessionPks, $rendererVersion, $observerSchemaVersion],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * Active candidate observations for Observer/Reflector input and threshold tokens.
     *
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }>
     */
    public function listActiveCandidateObservations(int $sessionPk): array
    {
        return $this->activeCandidateRows($sessionPk);
    }

    /** @return array{count: int, token_count: int} */
    public function activeCandidateStats(int $sessionPk): array
    {
        // Persisted token_count is not authoritative; estimate from content in PHP so
        // threshold arithmetic matches OM's estimator exactly.
        [$predicate, $parameters] = $this->activeCandidatePredicate($sessionPk);
        $count = 0;
        $tokens = 0;
        foreach ($this->connection->iterateColumn('SELECT o.content FROM om_observations o WHERE '.$predicate, $parameters) as $content) {
            ++$count;
            $tokens += OmTokenEstimator::estimate((string) $content);
        }

        return ['count' => $count, 'token_count' => $tokens];
    }

    /**
     * Bounded project-wide exact or unique-prefix observation lookup.
     *
     * @return list<array{
     *   observation_id: string,
     *   session_pk: int,
     *   run_id: string,
     *   source_kind: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }>
     */
    public function findObservationsByIdPrefix(int $projectId, string $idPrefix): array
    {
        $idPrefix = strtolower(trim($idPrefix));
        if ('' === $idPrefix || 1 !== preg_match('/^[0-9a-f]+$/', $idPrefix)) {
            return [];
        }

        $exact = 64 === \strlen($idPrefix);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.observation_id, o.memory_session_id, s.session_id AS run_id, s.source_kind, o.content, o.content_hash, o.relevance,
                    o.timestamp, o.token_count, o.source_refs_json, o.source_start_seq, o.source_end_seq, o.created_at
             FROM om_observations o
             INNER JOIN om_sessions s ON s.id = o.memory_session_id
             WHERE s.project_id = ? AND o.observation_id '.($exact ? '= ?' : 'LIKE ?').'
             ORDER BY o.observation_id ASC
             LIMIT 51',
            [$projectId, $exact ? $idPrefix : $idPrefix.'%'],
        );

        $out = [];
        foreach ($rows as $row) {
            $mapped = $this->mapObservationRow($row);
            $out[] = $mapped + [
                'session_pk' => (int) $row['memory_session_id'],
                'run_id' => (string) $row['run_id'],
                'source_kind' => (string) $row['source_kind'],
            ];
        }

        return $out;
    }

    /**
     * Active candidate set for threshold tokens / observation_set_hash / Reflector input.
     *
     * Before first generation: all observations for the session.
     * After a succeeded active generation: retained observations for that generation
     * UNION observations with source_end_seq greater than the generation required_end_seq.
     *
     * @return array{
     *   observation_ids: list<string>,
     *   token_count: int,
     *   observation_set_hash: string,
     *   max_source_end_seq: int,
     *   observations: list<array{
     *     observation_id: string,
     *     content: string,
     *     content_hash: string,
     *     relevance: string,
     *     timestamp: string,
     *     token_count: int,
     *     source_refs_json: string,
     *     source_start_seq: int,
     *     source_end_seq: int,
     *     created_at: string
     *   }>
     * }
     */
    public function activeCandidateSet(int $sessionPk, string $runId): array
    {
        return $this->buildCandidateSetPayload($runId, $this->activeCandidateRows($sessionPk));
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string
     * }> $observations
     *
     * @return array{status: 'inserted'|'noop', observation_count: int}
     */
    public function commitChunkPartCoverage(
        int $sessionPk,
        string $coverageKey,
        string $boundaryKey,
        int $sourceStartSeq,
        int $sourceEndSeq,
        string $chunkKey,
        int $partIndex,
        int $partCount,
        string $sourceDigest,
        string $partDigest,
        string $rendererVersion,
        string $observerSchemaVersion,
        string $observerModel,
        array $observations,
        string $coveredAt,
    ): array {
        $existing = $this->connection->fetchAssociative(
            'SELECT source_digest, part_digest, observation_count FROM om_observer_coverage WHERE memory_session_id = ? AND coverage_key = ?',
            [$sessionPk, $coverageKey],
        );

        if (false !== $existing) {
            if (($existing['source_digest'] ?? '') !== $sourceDigest
                || ($existing['part_digest'] ?? '') !== $partDigest) {
                throw new OmConflictException(\sprintf('Coverage conflict for key %s: digest mismatch.', $coverageKey));
            }

            return [
                'status' => 'noop',
                'observation_count' => (int) ($existing['observation_count'] ?? 0),
            ];
        }

        $this->connection->beginTransaction();
        try {
            foreach ($observations as $observation) {
                // PostgreSQL aborts the transaction on a unique violation, so check first
                // (the per-session lock serializes writers) instead of catching it.
                $prior = $this->connection->fetchOne(
                    'SELECT content_hash FROM om_observations WHERE memory_session_id = ? AND observation_id = ?',
                    [$sessionPk, $observation['observation_id']],
                );
                if (false !== $prior) {
                    if ($prior !== $observation['content_hash']) {
                        throw new OmConflictException(\sprintf('Observation conflict for id %s: content_hash mismatch.', $observation['observation_id']));
                    }
                    continue;
                }

                $this->connection->insert('om_observations', [
                    'memory_session_id' => $sessionPk,
                    'observation_id' => $observation['observation_id'],
                    'boundary_key' => $boundaryKey,
                    'source_start_seq' => $sourceStartSeq,
                    'source_end_seq' => $sourceEndSeq,
                    'source_refs_json' => $observation['source_refs_json'],
                    'content' => $observation['content'],
                    'content_hash' => $observation['content_hash'],
                    'relevance' => $observation['relevance'],
                    'timestamp' => $observation['timestamp'],
                    'token_count' => $observation['token_count'],
                    'observer_model' => $observerModel,
                    'observer_schema_version' => $observerSchemaVersion,
                    'created_at' => $coveredAt,
                ]);
            }

            $this->connection->insert('om_observer_coverage', [
                'memory_session_id' => $sessionPk,
                'coverage_key' => $coverageKey,
                'boundary_key' => $boundaryKey,
                'source_start_seq' => $sourceStartSeq,
                'source_end_seq' => $sourceEndSeq,
                'chunk_key' => $chunkKey,
                'part_index' => $partIndex,
                'part_count' => $partCount,
                'source_digest' => $sourceDigest,
                'part_digest' => $partDigest,
                'renderer_version' => $rendererVersion,
                'observer_schema_version' => $observerSchemaVersion,
                'observation_count' => \count($observations),
                'covered_at' => $coveredAt,
            ]);
            MemoryRevision::bumpForSession($this->connection, $sessionPk);

            $this->connection->commit();
        } catch (UniqueConstraintViolationException $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $again = $this->connection->fetchAssociative(
                'SELECT source_digest, part_digest, observation_count FROM om_observer_coverage WHERE memory_session_id = ? AND coverage_key = ?',
                [$sessionPk, $coverageKey],
            );
            if (false === $again
                || ($again['source_digest'] ?? '') !== $sourceDigest
                || ($again['part_digest'] ?? '') !== $partDigest) {
                throw new OmConflictException(\sprintf('Coverage conflict for key %s after concurrent insert.', $coverageKey), previous: $e);
            }

            return [
                'status' => 'noop',
                'observation_count' => (int) ($again['observation_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return [
            'status' => 'inserted',
            'observation_count' => \count($observations),
        ];
    }

    /**
     * Newest-first project-scoped observation page for admin browsing.
     *
     * @return array{items: list<array{
     *   observation_id: string,
     *   session_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string,
     *   created_at: string
     * }>, page: int, page_size: int, total: int, page_count: int}
     */
    public function pageForProject(int $projectId, int $page = 1, ?string $sessionId = null): array
    {
        $page = max(1, $page);
        $where = ' WHERE s.project_id = ?';
        $params = [$projectId];
        if (null !== $sessionId) {
            $where .= ' AND s.session_id = ?';
            $params[] = $sessionId;
        }
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM om_observations o
             INNER JOIN om_sessions s ON s.id = o.memory_session_id'.$where,
            $params,
        );
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = 0 === $total ? [] : $this->connection->fetchAllAssociative(
            'SELECT o.observation_id, s.session_id, o.content, o.relevance, o.timestamp, o.created_at
             FROM om_observations o
             INNER JOIN om_sessions s ON s.id = o.memory_session_id'.$where.'
             ORDER BY o.created_at DESC, o.observation_id DESC
             LIMIT ? OFFSET ?',
            [...$params, self::PAGE_SIZE, $offset],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'observation_id' => (string) $row['observation_id'],
                'session_id' => (string) $row['session_id'],
                'content' => (string) $row['content'],
                'relevance' => (string) $row['relevance'],
                'timestamp' => (string) $row['timestamp'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total' => $total,
            'page_count' => $pageCount,
        ];
    }

    /** @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }> */
    private function activeCandidateRows(int $sessionPk): array
    {
        [$predicate, $parameters] = $this->activeCandidatePredicate($sessionPk);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.observation_id, o.content, o.content_hash, o.relevance, o.timestamp, o.token_count,
                    o.source_refs_json, o.source_start_seq, o.source_end_seq, o.created_at
             FROM om_observations o WHERE '.$predicate.' ORDER BY o.timestamp ASC, o.observation_id ASC',
            $parameters,
        );

        return array_values(array_map($this->mapObservationRow(...), $rows));
    }

    /** @return array{string, list<string|int>} */
    private function activeCandidatePredicate(int $sessionPk): array
    {
        $active = $this->connection->fetchAssociative(
            'SELECT g.id, g.required_end_seq
             FROM om_sessions s
             INNER JOIN om_memory_generations g ON g.id = s.active_generation_id
             WHERE s.id = ? AND g.status = ?',
            [$sessionPk, MemoryGenerationStatus::Succeeded->value],
        );
        if (false === $active || null === $active['required_end_seq']) {
            return ['o.memory_session_id = ?', [$sessionPk]];
        }

        return [
            'o.memory_session_id = ? AND (
                    EXISTS (
                        SELECT 1 FROM om_generation_retained_observations r
                        WHERE r.generation_id = ? AND r.observation_id = o.observation_id
                    )
                    OR o.source_end_seq > ?
               )',
            [$sessionPk, (int) $active['id'], (int) $active['required_end_seq']],
        ];
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }> $observations
     *
     * @return array{
     *   observation_ids: list<string>,
     *   token_count: int,
     *   observation_set_hash: string,
     *   max_source_end_seq: int,
     *   observations: list<array{
     *     observation_id: string,
     *     content: string,
     *     content_hash: string,
     *     relevance: string,
     *     timestamp: string,
     *     token_count: int,
     *     source_refs_json: string,
     *     source_start_seq: int,
     *     source_end_seq: int,
     *     created_at: string
     *   }>
     * }
     */
    private function buildCandidateSetPayload(string $runId, array $observations): array
    {
        $ids = [];
        $tokens = 0;
        $maxSourceEndSeq = 0;
        foreach ($observations as $observation) {
            $ids[] = $observation['observation_id'];
            $tokens += OmTokenEstimator::estimate($observation['content']);
            $maxSourceEndSeq = max($maxSourceEndSeq, $observation['source_end_seq']);
        }
        sort($ids, \SORT_STRING);

        return [
            'observation_ids' => $ids,
            'token_count' => $tokens,
            // Hash remains IDs-only (task formula observation-set-v1).
            'observation_set_hash' => OmIdentity::observationSetHash($runId, $ids),
            'max_source_end_seq' => $maxSourceEndSeq,
            'observations' => $observations,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }
     */
    private function mapObservationRow(array $row): array
    {
        return [
            'observation_id' => (string) ($row['observation_id'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'relevance' => (string) ($row['relevance'] ?? OmIdentity::RELEVANCE_MEDIUM),
            'timestamp' => (string) ($row['timestamp'] ?? '1970-01-01 00:00'),
            'token_count' => (int) ($row['token_count'] ?? 0),
            'source_refs_json' => (string) ($row['source_refs_json'] ?? '[]'),
            'source_start_seq' => (int) ($row['source_start_seq'] ?? 0),
            'source_end_seq' => (int) ($row['source_end_seq'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
