<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\IndexProjectsTick;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Env-configured periodic indexing schedule for the default Symfony schedule.
 */
#[AsSchedule('default')]
final class IndexProjectsSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly int $indexIntervalSeconds,
    ) {
        if ($this->indexIntervalSeconds < 1) {
            throw new \InvalidArgumentException('INDEX_INTERVAL must be a positive number of seconds.');
        }
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->add(RecurringMessage::every($this->indexIntervalSeconds, new IndexProjectsTick()));
    }
}
