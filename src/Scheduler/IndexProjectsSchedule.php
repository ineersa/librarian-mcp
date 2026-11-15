<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ScanProjectMessage;
use App\Project\ProjectManager;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;

/** Periodically discovers registered sources; successful discovery queues indexing. */
#[AsSchedule('default')]
final class IndexProjectsSchedule implements ScheduleProviderInterface
{
    private const string PROVIDER_ID = 'project-indexes';
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly int $indexIntervalSeconds,
    ) {
        if ($this->indexIntervalSeconds < 1) {
            throw new \InvalidArgumentException('INDEX_INTERVAL must be a positive number of seconds.');
        }
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())->add(RecurringMessage::every(
            $this->indexIntervalSeconds,
            new CallbackMessageProvider($this->messages(...), self::PROVIDER_ID, 'Discover project sources and check indexes'),
        ));
    }

    /** @return iterable<Envelope> */
    private function messages(): iterable
    {
        $projects = $this->projectRepository->createQueryBuilder('p')->select('p.id')
            ->orderBy('p.id', 'ASC')->getQuery()->toIterable();
        foreach ($projects as $project) {
            $id = (int) $project['id'];
            yield new Envelope(new ScanProjectMessage($id), [new DeduplicateStamp(
                ProjectManager::SCAN_DEDUPE_PREFIX.$id,
                ProjectManager::SCAN_DEDUPE_TTL_SECONDS,
                true,
            )]);
        }
    }
}
