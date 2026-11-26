<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SessionBackfillResult
{
    public function __construct(
        public MemorySourceKind $sourceKind,
        public int $queuedSessions,
        public int $skippedSessions,
        public int $signals,
    ) {
    }
}
