<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;

/**
 * Per-project memory change counter (port of OM om_source_revision).
 *
 * Call inside the same transaction as the memory write it records, so indexing
 * observes the revision exactly when the rows become visible.
 */
final class MemoryRevision
{
    public static function bumpForProject(Connection $connection, int $projectId): void
    {
        $connection->executeStatement('UPDATE projects SET memory_revision = memory_revision + 1 WHERE id = ?', [$projectId]);
    }

    public static function bumpForSession(Connection $connection, int $sessionPk): void
    {
        $connection->executeStatement(
            'UPDATE projects SET memory_revision = memory_revision + 1
             WHERE id = (SELECT project_id FROM om_sessions WHERE id = ?)',
            [$sessionPk],
        );
    }

    public static function current(Connection $connection, int $projectId): int
    {
        return (int) $connection->fetchOne('SELECT memory_revision FROM projects WHERE id = ?', [$projectId]);
    }
}
