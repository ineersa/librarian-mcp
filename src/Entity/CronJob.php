<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Jobs run by the cron-run system: at most one successful run per job per day.
 */
enum CronJob: string
{
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Backup => 'Daily backup',
        };
    }
}
