<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\Memory\SourceCheckpoint;
use Doctrine\DBAL\Connection;

/**
 * Source byte checkpoints per memory session (see MemorySourceCheckpoint).
 */
final readonly class SourceCheckpointRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Nearest checkpoint strictly before $seq. */
    public function latestBefore(int $sessionPk, int $seq): ?SourceCheckpoint
    {
        $row = $this->connection->fetchAssociative(
            'SELECT seq, byte_offset, boundary_hash FROM om_source_checkpoints
             WHERE memory_session_id = ? AND seq < ? ORDER BY seq DESC LIMIT 1',
            [$sessionPk, $seq],
        );

        return false === $row ? null : new SourceCheckpoint((int) $row['seq'], (int) $row['byte_offset'], (string) $row['boundary_hash']);
    }

    public function record(int $sessionPk, SourceCheckpoint $checkpoint): void
    {
        // A rewritten source re-records the same seq at its new position.
        $this->connection->executeStatement(
            'INSERT INTO om_source_checkpoints (memory_session_id, seq, byte_offset, boundary_hash) VALUES (?, ?, ?, ?)
             ON CONFLICT (memory_session_id, seq) DO UPDATE SET byte_offset = EXCLUDED.byte_offset, boundary_hash = EXCLUDED.boundary_hash',
            [$sessionPk, $checkpoint->seq, $checkpoint->offset, $checkpoint->boundaryHash],
        );
    }
}
