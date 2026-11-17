<?php

declare(strict_types=1);

namespace App\Message;

use App\ObservationalMemory\Ingest\MemoryTerminalStatus;

/**
 * Port of the OM `observational_memory.observe_boundary` job payload.
 */
final class ObserveMemorySessionMessage
{
    public function __construct(
        public readonly int $sessionPk,
        public readonly int $terminalEndSeq,
        public readonly MemoryTerminalStatus $terminalStatus,
    ) {
    }
}
