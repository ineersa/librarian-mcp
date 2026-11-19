<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\CronJob;
use App\Message\RunCronJobMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Checks every cron job periodically; the runner skips jobs that already succeeded today.
 * Jobs are redispatched to the `cron` transport so long work never blocks the scheduler.
 */
#[AsSchedule(self::NAME)]
final class CronSchedule implements ScheduleProviderInterface
{
    public const string NAME = 'cron';
    public const string TRANSPORT = 'cron';
    private const string CHECK_INTERVAL = '30 minutes';

    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        if (null !== $this->schedule) {
            return $this->schedule;
        }

        $schedule = new Schedule();
        foreach (CronJob::cases() as $job) {
            $schedule->add(RecurringMessage::every(
                self::CHECK_INTERVAL,
                new RedispatchMessage(new RunCronJobMessage($job), self::TRANSPORT),
            ));
        }

        return $this->schedule = $schedule;
    }
}
