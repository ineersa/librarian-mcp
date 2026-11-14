<?php

declare(strict_types=1);

namespace App\Index\Status;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Publishes bounded Turbo Stream status replacements after committed work.
 *
 * Never performs source IO or provider requests. Mercure publish happens after
 * Doctrine commits, outside metadata write transactions.
 */
final class ProjectStatusPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectIndexStatusFactory $statusFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(int $projectId): void
    {
        if ($projectId < 1) {
            return;
        }

        try {
            $project = $this->projectRepository->find($projectId);
            if (!$project instanceof Project || null === $project->id) {
                return;
            }

            $status = $this->statusFactory->forProject($project);
            $payload = $this->twig->render('admin/project/status/update.stream.html.twig', [
                'project' => $project,
                'indexStatus' => $status,
            ]);

            $this->hub->publish(new Update(
                [
                    ProjectStatusTopics::forProject($project->id),
                    ProjectStatusTopics::LIST_STATUS,
                ],
                $payload,
                private: true,
            ));
        } catch (\Throwable $exception) {
            // Status transport is best-effort; indexing/discovery must remain usable.
            $this->logger->warning('Project status publish deferred.', [
                'projectId' => $projectId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
