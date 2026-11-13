<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Scheduler tick that queues indexing for a bounded page of ready projects.
 *
 * Continuation ticks reuse the same dedupe key so backlog does not accumulate.
 */
final class IndexProjectsTick
{
    public function __construct(
        public readonly ?int $afterProjectId = null,
    ) {
    }
}
