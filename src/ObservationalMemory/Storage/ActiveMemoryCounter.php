<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\Entity\MemoryGenerationStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Project-scoped active observation/reflection counts using OM selection semantics.
 *
 * Observations: all rows when a session has no succeeded active generation with a
 * required_end_seq; otherwise retained rows plus observations after that end seq.
 * Reflections: only those linked to the session's active generation.
 */
final readonly class ActiveMemoryCounter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function forProject(int $projectId): ActiveMemoryCounts
    {
        return $this->forProjects([$projectId])[$projectId] ?? new ActiveMemoryCounts(0, 0);
    }

    /**
     * @param list<int> $projectIds
     *
     * @return array<int, ActiveMemoryCounts>
     */
    public function forProjects(array $projectIds): array
    {
        $counts = [];
        foreach ($projectIds as $projectId) {
            $counts[$projectId] = new ActiveMemoryCounts(0, 0);
        }
        if ([] === $projectIds) {
            return $counts;
        }

        $observationRows = $this->connection->fetchAllAssociative(
            'SELECT s.project_id AS project_id, COUNT(o.observation_id) AS observation_count
             FROM om_sessions s
             INNER JOIN om_observations o ON o.memory_session_id = s.id
             LEFT JOIN om_memory_generations g
                ON g.id = s.active_generation_id AND g.status = ?
             WHERE s.project_id IN (?)
               AND (
                    g.id IS NULL
                    OR g.required_end_seq IS NULL
                    OR EXISTS (
                        SELECT 1 FROM om_generation_retained_observations r
                        WHERE r.generation_id = g.id AND r.observation_id = o.observation_id
                    )
                    OR o.source_end_seq > g.required_end_seq
               )
             GROUP BY s.project_id',
            [MemoryGenerationStatus::Succeeded->value, $projectIds],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        );
        foreach ($observationRows as $row) {
            $projectId = (int) $row['project_id'];
            $counts[$projectId] = new ActiveMemoryCounts(
                (int) $row['observation_count'],
                $counts[$projectId]->reflections,
            );
        }

        $reflectionRows = $this->connection->fetchAllAssociative(
            'SELECT s.project_id AS project_id, COUNT(r.reflection_id) AS reflection_count
             FROM om_sessions s
             INNER JOIN om_generation_reflections gr ON gr.generation_id = s.active_generation_id
             INNER JOIN om_reflections r
                ON r.memory_session_id = s.id AND r.reflection_id = gr.reflection_id
             WHERE s.project_id IN (?)
             GROUP BY s.project_id',
            [$projectIds],
            [ArrayParameterType::INTEGER],
        );
        foreach ($reflectionRows as $row) {
            $projectId = (int) $row['project_id'];
            $existing = $counts[$projectId] ?? new ActiveMemoryCounts(0, 0);
            $counts[$projectId] = new ActiveMemoryCounts(
                $existing->observations,
                (int) $row['reflection_count'],
            );
        }

        return $counts;
    }
}
