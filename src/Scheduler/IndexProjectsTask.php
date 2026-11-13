<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\ProjectHealth;
use App\Index\ProjectIndexManager;
use App\Message\IndexProjectsTick;
use App\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles scheduler ticks by queueing indexing for ready projects.
 */
#[AsMessageHandler]
final class IndexProjectsTask
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectIndexManager $projectIndexManager,
    ) {
    }

    public function __invoke(IndexProjectsTick $message): void
    {
        unset($message);

        $projects = $this->projectRepository->findBy(
            ['health' => ProjectHealth::Ready],
            ['id' => 'ASC'],
            100,
        );

        foreach ($projects as $project) {
            if (null === $project->id) {
                continue;
            }
            $this->projectIndexManager->queueId($project->id);
        }
    }
}
