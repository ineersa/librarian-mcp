<?php

declare(strict_types=1);

namespace App\Index\Source;

/**
 * Canonical OM observation/reflection projection used for indexing.
 */
final readonly class CanonicalMemory
{
    public function __construct(
        public MemoryKind $kind,
        public string $sourceId,
        public string $sessionId,
        public string $content,
        public int $occurredAt,
        public ?string $relevance,
        public string $contentSignature,
    ) {
    }

    public function cursorKey(): string
    {
        return $this->kind->value.'|'.$this->sourceId;
    }
}
