<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Queues read-only Hatfield source discovery for one registered project.
 */
final class ScanProjectMessage
{
    public function __construct(
        public readonly int $projectId,
    ) {
    }
}
