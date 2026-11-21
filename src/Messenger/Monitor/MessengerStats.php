<?php

declare(strict_types=1);

namespace App\Messenger\Monitor;

/**
 * @phpstan-type FailedJobRow array{id: string, class: string, transport: string|null, failedAt: \DateTimeInterface|null, retries: int, errorClass: string|null, error: string|null, sessionId: int|null, target: int|null, projectId: int|null, projectName: string|null, covered: bool|null, contiguousEnd: int|null}
 */
final readonly class MessengerStats
{
    /**
     * @param array<string, array{queued: int|null, delayed: int|null}> $queues
     * @param list<FailedJobRow>|null                                   $failures
     */
    public function __construct(
        public array $queues,
        public ?int $failedTotal,
        public ?array $failures,
    ) {
    }
}
