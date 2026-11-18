<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

use App\Memory\OpenToolCallRef;
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
            'SELECT seq, byte_offset, boundary_hash, open_tool_calls FROM om_source_checkpoints
             WHERE memory_session_id = ? AND seq < ? ORDER BY seq DESC LIMIT 1',
            [$sessionPk, $seq],
        );
        if (false === $row) {
            return null;
        }

        $calls = [];
        $decoded = json_decode((string) $row['open_tool_calls'], true);
        foreach (\is_array($decoded) ? $decoded : [] as $call) {
            if (\is_array($call) && \is_string($call['id'] ?? null) && \is_int($call['seq'] ?? null) && \is_int($call['offset'] ?? null) && \is_int($call['length'] ?? null)) {
                $calls[] = new OpenToolCallRef($call['id'], $call['seq'], $call['offset'], $call['length']);
            }
        }

        return new SourceCheckpoint((int) $row['seq'], (int) $row['byte_offset'], (string) $row['boundary_hash'], $calls);
    }

    public function record(int $sessionPk, SourceCheckpoint $checkpoint): void
    {
        // A rewritten source re-records the same seq at its new position.
        $this->connection->executeStatement(
            'INSERT INTO om_source_checkpoints (memory_session_id, seq, byte_offset, boundary_hash, open_tool_calls) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (memory_session_id, seq) DO UPDATE SET byte_offset = EXCLUDED.byte_offset, boundary_hash = EXCLUDED.boundary_hash, open_tool_calls = EXCLUDED.open_tool_calls',
            [
                $sessionPk,
                $checkpoint->seq,
                $checkpoint->offset,
                $checkpoint->boundaryHash,
                json_encode(array_map(
                    static fn (OpenToolCallRef $call): array => ['id' => $call->id, 'seq' => $call->seq, 'offset' => $call->offset, 'length' => $call->length],
                    $checkpoint->openToolCalls,
                ), \JSON_THROW_ON_ERROR),
            ],
        );
    }
}
