<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ClaudeSessionBackfillResult
{
    public function __construct(
        public int $queuedSessions,
        public int $skippedSessions,
        public int $signals,
    ) {
    }
}
