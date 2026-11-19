<?php

declare(strict_types=1);

namespace App\Cron;

enum CronRunOutcome: string
{
    /** Already succeeded today and not forced. */
    case Skipped = 'skipped';
    /** Another run of the same job holds the lock. */
    case Busy = 'busy';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
