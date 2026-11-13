<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\ProjectHealth;
use App\Index\ProjectIndexManager;
use App\Message\IndexProjectsTick;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Handles scheduler ticks by queueing indexing for a bounded page of ready projects.
 */
#[AsMessageHandler]
final class IndexProjectsTask
{
    public const int PAGE_SIZE = 100;

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(IndexProjectsTick $message): void
    {
        $qb = $this->projectRepository->createQueryBuilder('p')
            ->andWhere('p.health = :health')
            ->setParameter('health', ProjectHealth::Ready)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(self::PAGE_SIZE);

        if (null !== $message->afterProjectId) {
            $qb->andWhere('p.id > :afterId')
                ->setParameter('afterId', $message->afterProjectId);
        }

        /** @var list<\App\Entity\Project> $projects */
        $projects = $qb->getQuery()->getResult();
        $lastId = null;

        foreach ($projects as $project) {
            if (null === $project->id) {
                continue;
            }
            $this->projectIndexManager->queueId($project->id);
            $lastId = $project->id;
        }

        if (\count($projects) >= self::PAGE_SIZE && null !== $lastId) {
            // Continuation uses the same tick dedupe key so only one page is
            // admitted at a time; no custom polling coordinator.
            $this->messageBus->dispatch(
                new IndexProjectsTick($lastId),
                [
                    new DeduplicateStamp(
                        ProjectIndexManager::TICK_DEDUPE_KEY,
                        ProjectIndexManager::DEDUPE_TTL_SECONDS,
                        true,
                    ),
                ],
            );
        }
    }
}
