<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\Entity\MemoryGenerationFailureCode;
use App\Entity\MemoryGenerationStatus;
use Doctrine\DBAL\Connection;

/**
 * Port of OM MemoryGenerationRepository onto Librarian PostgreSQL.
 *
 * `generation_id` stays the OM threshold-generation-v1 hash; the integer primary key
 * links reflections, retained observations and the session active pointer.
 * Only the threshold trigger exists; the OM compaction trigger was retired upstream.
 */
final readonly class MemoryGenerationRepository
{
    public const string CLAIM_CLAIMED = 'claimed';
    public const string CLAIM_ALREADY_SUCCEEDED = 'already_succeeded';
    public const string CLAIM_CONFLICT = 'conflict';

    public const string PAGE_QUERY = 'reflections_page';
    public const int PAGE_SIZE = 20;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function activeGenerationId(int $sessionPk): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT g.generation_id FROM om_sessions s
             INNER JOIN om_memory_generations g ON g.id = s.active_generation_id
             WHERE s.id = ?',
            [$sessionPk],
        );

        return \is_string($id) && '' !== $id ? $id : null;
    }

    /**
     * Bounded project-wide exact or unique-prefix reflection lookup.
     *
     * @return list<array{
     *   reflection_id: string,
     *   session_pk: int,
     *   run_id: string,
     *   source_kind: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }>
     */
    public function findReflectionsByIdPrefix(int $projectId, string $idPrefix): array
    {
        $idPrefix = strtolower(trim($idPrefix));
        if ('' === $idPrefix || 1 !== preg_match('/^[0-9a-f]+$/', $idPrefix)) {
            return [];
        }

        $exact = 64 === \strlen($idPrefix);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.reflection_id, r.memory_session_id, s.session_id AS run_id, s.source_kind, r.content,
                    r.supporting_observation_ids_json, r.token_count
             FROM om_reflections r
             INNER JOIN om_sessions s ON s.id = r.memory_session_id
             WHERE s.project_id = ? AND r.reflection_id '.($exact ? '= ?' : 'LIKE ?').'
             ORDER BY r.reflection_id ASC
             LIMIT 51',
            [$projectId, $exact ? $idPrefix : $idPrefix.'%'],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'reflection_id' => (string) $row['reflection_id'],
                'session_pk' => (int) $row['memory_session_id'],
                'run_id' => (string) $row['run_id'],
                'source_kind' => (string) $row['source_kind'],
                'content' => (string) $row['content'],
                'supporting_observation_ids_json' => (string) $row['supporting_observation_ids_json'],
                'token_count' => (int) $row['token_count'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int,
     *   position: int
     * }>
     */
    public function listActiveReflections(int $sessionPk): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT gr.reflection_id, gr.position, r.content, r.supporting_observation_ids_json, r.token_count
             FROM om_sessions s
             INNER JOIN om_generation_reflections gr ON gr.generation_id = s.active_generation_id
             INNER JOIN om_reflections r ON r.memory_session_id = s.id AND r.reflection_id = gr.reflection_id
             WHERE s.id = ?
             ORDER BY gr.position ASC',
            [$sessionPk],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'reflection_id' => (string) $row['reflection_id'],
                'content' => (string) $row['content'],
                'supporting_observation_ids_json' => (string) $row['supporting_observation_ids_json'],
                'token_count' => (int) $row['token_count'],
                'position' => (int) $row['position'],
            ];
        }

        return $out;
    }

    /**
     * Boundary threshold dispatch guard: suppress new job creation when this exact
     * observation set already has a running, succeeded, or failed generation.
     */
    public function hasTerminalOrInFlightGenerationForSet(int $sessionPk, string $observationSetHash): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(1) FROM om_memory_generations WHERE memory_session_id = ? AND observation_set_hash = ?',
            [$sessionPk, $observationSetHash],
        ) > 0;
    }

    /**
     * Claim a generation row for work. Callers hold the per-session lock, so a row
     * still `running` here was orphaned by a crashed worker and is reclaimed.
     *
     * @return self::CLAIM_*
     */
    public function claimGeneration(
        int $sessionPk,
        string $generationId,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        string $now,
        ?int $requiredStartSeq,
        ?int $requiredEndSeq,
    ): string {
        $existing = $this->connection->fetchAssociative(
            'SELECT status FROM om_memory_generations WHERE memory_session_id = ? AND generation_id = ?',
            [$sessionPk, $generationId],
        );
        if (false !== $existing) {
            return match (MemoryGenerationStatus::tryFrom((string) $existing['status'])) {
                MemoryGenerationStatus::Succeeded => self::CLAIM_ALREADY_SUCCEEDED,
                // Reclaim orphaned running rows and allow redelivery of failed claims.
                MemoryGenerationStatus::Running, MemoryGenerationStatus::Failed => 1 === $this->connection->executeStatement(
                    'UPDATE om_memory_generations
                     SET status = ?, failure_code = NULL, completed_at = NULL, created_at = ?
                     WHERE memory_session_id = ? AND generation_id = ? AND status = ?',
                    [MemoryGenerationStatus::Running->value, $now, $sessionPk, $generationId, $existing['status']],
                ) ? self::CLAIM_CLAIMED : self::CLAIM_CONFLICT,
                null => self::CLAIM_CONFLICT,
            };
        }

        $this->connection->insert('om_memory_generations', [
            'memory_session_id' => $sessionPk,
            'generation_id' => $generationId,
            'status' => MemoryGenerationStatus::Running->value,
            'observation_set_hash' => $observationSetHash,
            'reflector_model' => $reflectorModel,
            'reflector_schema_version' => $reflectorSchemaVersion,
            'required_start_seq' => $requiredStartSeq,
            'required_end_seq' => $requiredEndSeq,
            'failure_code' => null,
            'created_at' => $now,
            'completed_at' => null,
        ]);

        return self::CLAIM_CLAIMED;
    }

    /**
     * Commit a successful generation and promote the session active pointer in one transaction.
     *
     * @param list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }> $reflections
     * @param list<string> $retainedObservationIds
     */
    public function commitSucceededGeneration(
        int $sessionPk,
        string $generationId,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        array $reflections,
        array $retainedObservationIds,
        string $now,
    ): void {
        $this->connection->transactional(static function (Connection $connection) use (
            $sessionPk, $generationId, $observationSetHash, $reflectorModel, $reflectorSchemaVersion,
            $reflections, $retainedObservationIds, $now,
        ): void {
            $existing = $connection->fetchAssociative(
                'SELECT id, status, observation_set_hash, reflector_model, reflector_schema_version
                 FROM om_memory_generations WHERE memory_session_id = ? AND generation_id = ? FOR UPDATE',
                [$sessionPk, $generationId],
            );
            if (false === $existing) {
                throw new \RuntimeException(\sprintf('Generation %s not found for success commit.', $generationId));
            }
            if (MemoryGenerationStatus::Succeeded->value === $existing['status']) {
                return;
            }
            if (MemoryGenerationStatus::Running->value !== $existing['status']) {
                throw new OmConflictException(\sprintf('Generation %s is %s; cannot commit success.', $generationId, (string) $existing['status']));
            }
            if ($existing['observation_set_hash'] !== $observationSetHash
                || $existing['reflector_model'] !== $reflectorModel
                || $existing['reflector_schema_version'] !== $reflectorSchemaVersion) {
                throw new OmConflictException(\sprintf('Generation %s identity mismatch on success commit.', $generationId));
            }
            $generationPk = (int) $existing['id'];

            foreach ($reflections as $position => $reflection) {
                $prior = $connection->fetchOne(
                    'SELECT content FROM om_reflections WHERE memory_session_id = ? AND reflection_id = ?',
                    [$sessionPk, $reflection['reflection_id']],
                );
                if (false === $prior) {
                    $connection->insert('om_reflections', [
                        'memory_session_id' => $sessionPk,
                        'reflection_id' => $reflection['reflection_id'],
                        'observation_set_hash' => $observationSetHash,
                        'content' => $reflection['content'],
                        'supporting_observation_ids_json' => $reflection['supporting_observation_ids_json'],
                        'token_count' => $reflection['token_count'],
                        'reflector_model' => $reflectorModel,
                        'reflector_schema_version' => $reflectorSchemaVersion,
                        'created_at' => $now,
                    ]);
                } elseif ($prior !== $reflection['content']) {
                    throw new OmConflictException(\sprintf('Reflection conflict for id %s.', $reflection['reflection_id']));
                }

                $connection->insert('om_generation_reflections', [
                    'generation_id' => $generationPk,
                    'reflection_id' => $reflection['reflection_id'],
                    'position' => $position,
                ]);
            }

            foreach ($retainedObservationIds as $position => $observationId) {
                $connection->insert('om_generation_retained_observations', [
                    'generation_id' => $generationPk,
                    'observation_id' => $observationId,
                    'position' => $position,
                ]);
            }

            $connection->executeStatement(
                'UPDATE om_memory_generations SET status = ?, completed_at = ?, failure_code = NULL WHERE id = ?',
                [MemoryGenerationStatus::Succeeded->value, $now, $generationPk],
            );
            $connection->executeStatement(
                'UPDATE om_sessions SET active_generation_id = ?, updated_at = ? WHERE id = ?',
                [$generationPk, (new \DateTimeImmutable($now))->format('Y-m-d H:i:s'), $sessionPk],
            );
            MemoryRevision::bumpForSession($connection, $sessionPk);
        });
    }

    public function markFailed(int $sessionPk, string $generationId, MemoryGenerationFailureCode $failureCode, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE om_memory_generations SET status = ?, failure_code = ?, completed_at = ?
             WHERE memory_session_id = ? AND generation_id = ? AND status = ?',
            [MemoryGenerationStatus::Failed->value, $failureCode->value, $now, $sessionPk, $generationId, MemoryGenerationStatus::Running->value],
        );
    }

    /**
     * Soft-complete a claimed generation without promoting active memory (threshold no-op).
     */
    public function markSucceededNoop(int $sessionPk, string $generationId, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE om_memory_generations SET status = ?, completed_at = ?, failure_code = NULL
             WHERE memory_session_id = ? AND generation_id = ? AND status = ?',
            [MemoryGenerationStatus::Succeeded->value, $now, $sessionPk, $generationId, MemoryGenerationStatus::Running->value],
        );
    }

    /**
     * Newest-first project-scoped reflection page for admin browsing.
     *
     * @return array{items: list<array{
     *   reflection_id: string,
     *   session_id: string,
     *   content: string,
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
            'SELECT COUNT(1) FROM om_reflections r
             INNER JOIN om_sessions s ON s.id = r.memory_session_id'.$where,
            $params,
        );
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = 0 === $total ? [] : $this->connection->fetchAllAssociative(
            'SELECT r.reflection_id, s.session_id, r.content, r.created_at
             FROM om_reflections r
             INNER JOIN om_sessions s ON s.id = r.memory_session_id'.$where.'
             ORDER BY r.created_at DESC, r.reflection_id DESC
             LIMIT ? OFFSET ?',
            [...$params, self::PAGE_SIZE, $offset],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'reflection_id' => (string) $row['reflection_id'],
                'session_id' => (string) $row['session_id'],
                'content' => (string) $row['content'],
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
}
