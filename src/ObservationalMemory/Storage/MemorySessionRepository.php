<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;

/**
 * Minimal session lookups for workers (DBAL, no identity map across long jobs).
 */
final readonly class MemorySessionRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{session_pk: int, project_id: int, source_kind: string, run_id: string, sessions_path: ?string}|null
     */
    public function find(int $sessionPk): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT s.id, s.project_id, s.source_kind, s.session_id, p.sessions_path
             FROM om_sessions s INNER JOIN projects p ON p.id = s.project_id WHERE s.id = ?',
            [$sessionPk],
        );
        if (false === $row) {
            return null;
        }

        return [
            'session_pk' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'source_kind' => (string) $row['source_kind'],
            'run_id' => (string) $row['session_id'],
            'sessions_path' => null === $row['sessions_path'] ? null : (string) $row['sessions_path'],
        ];
    }
}
