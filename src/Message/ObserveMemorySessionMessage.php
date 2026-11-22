<?php

declare(strict_types=1);

namespace App\Message;

use App\ObservationalMemory\Ingest\MemoryTerminalStatus;

/**
 * Port of the OM `observational_memory.observe_boundary` job payload.
 *
 * Nullable selection fields keep legacy queue payloads on the env baseline.
 * They are ordinary properties (not constructor-promoted readonly) so PHP
 * unserialization of older envelopes leaves them null instead of undefined.
 */
final class ObserveMemorySessionMessage
{
    public ?string $presetId = null;
    public ?string $reasoningLevel = null;
    public ?int $contextWindow = null;

    public function __construct(
        public readonly int $sessionPk,
        public readonly int $terminalEndSeq,
        public readonly MemoryTerminalStatus $terminalStatus,
        ?string $presetId = null,
        ?string $reasoningLevel = null,
        ?int $contextWindow = null,
    ) {
        $this->presetId = $presetId;
        $this->reasoningLevel = $reasoningLevel;
        $this->contextWindow = $contextWindow;
    }
}
