<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\CronJob;

/** Routed to the `cron` transport; `force` bypasses the once-per-day check. */
final readonly class RunCronJobMessage
{
    public function __construct(
        public CronJob $job,
        public bool $force = false,
    ) {
    }
}
