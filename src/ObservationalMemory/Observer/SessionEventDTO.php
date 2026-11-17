<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Observer;

/**
 * Port of Hatfield ExtensionApi SessionEventDTO: one canonical events.jsonl entry.
 *
 * Used only to rebuild OM source blocks from imported Hatfield journals.
 */
final readonly class SessionEventDTO
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public int $turnNo,
        public string $type,
        public array $payload = [],
        public string $createdAt = '',
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('SessionEventDTO runId must be a non-empty string.');
        }

        if ($this->seq < 1) {
            throw new \InvalidArgumentException('SessionEventDTO seq must be a positive integer.');
        }

        if ('' === trim($this->type)) {
            throw new \InvalidArgumentException('SessionEventDTO type must be a non-empty string.');
        }
    }
}
