<?php

declare(strict_types=1);

namespace App\Messenger\Monitor;

final readonly class MessengerStats
{
    /**
     * @param array<string, array{queued: int|null, delayed: int|null}>                                                                                                         $queues
     * @param list<array{id: string, class: string, transport: string|null, failedAt: \DateTimeInterface|null, retries: int, errorClass: string|null, error: string|null}>|null $failures
     */
    public function __construct(
        public array $queues,
        public ?int $failedTotal,
        public ?array $failures,
    ) {
    }
}
