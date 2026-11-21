<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Scheduler\CronSchedule;

enum MessengerTransport: string
{
    case Async = 'async';
    case Om = 'om';
    case Cron = CronSchedule::TRANSPORT;
    case Failed = 'failed';

    /** @return list<self> */
    public static function monitored(): array
    {
        return [self::Async, self::Om, self::Cron];
    }

    public function queueName(): string
    {
        // The async DSN uses Doctrine Messenger's default queue name.
        return self::Async === $this ? 'default' : $this->value;
    }
}
