<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\ProjectHealth;
use App\Index\ProjectIndexManager;
use App\Message\IndexProjectMessage;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;

/** Periodically checks registered sources for a new canonical revision. */
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
            new CallbackMessageProvider($this->messages(...), self::PROVIDER_ID, 'Check project source revisions'),
        ));
    }

    /** @return iterable<Envelope> */
    private function messages(): iterable
    {
        $projects = $this->projectRepository->createQueryBuilder('p')->select('p.id')
            ->andWhere('p.health = :health')->setParameter('health', ProjectHealth::Ready)
            ->orderBy('p.id', 'ASC')->getQuery()->toIterable();
        foreach ($projects as $project) {
            $id = (int) $project['id'];
            yield new Envelope(new IndexProjectMessage($id), [new DeduplicateStamp(
                ProjectIndexManager::DEDUPE_PREFIX.$id,
                ProjectIndexManager::DEDUPE_TTL_SECONDS,
                true,
            )]);
        }
    }
}
