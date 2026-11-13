<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Queues one bounded indexing work item for a registered project.
 */
final class IndexProjectMessage
{
    public function __construct(
        public readonly int $projectId,
    ) {
    }
}
