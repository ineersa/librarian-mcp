<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Project;
use App\Index\ProjectIndexManager;
use App\Index\Status\ProjectStatusPublisher;
use App\Message\ScanProjectMessage;
use App\Project\HatfieldSourceDiscovery;
use App\Project\ProjectDiscoveryResult;
use App\Project\ProjectSourceIssueCode;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ScanProjectMessageHandler
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HatfieldSourceDiscovery $sourceDiscovery,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ProjectIndexManager $projectIndexManager,
        private readonly ProjectStatusPublisher $projectStatusPublisher,
    ) {
    }

    public function __invoke(ScanProjectMessage $message): void
    {
        $project = $this->projectRepository->find($message->projectId);
        if (null === $project) {
            $this->logger->info('Skipping project scan because registration is missing.', [
                'projectId' => $message->projectId,
            ]);

            return;
        }

        $project->markScanning($this->clock->now());
        $this->entityManager->flush();
        $this->projectStatusPublisher->publish($message->projectId);

        $projectId = $project->id;
        $root = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;

        // Release the short write before filesystem / SQLite reads.
        $this->entityManager->clear();

        try {
            $result = $this->sourceDiscovery->discover($root);
        } catch (\Throwable $exception) {
            $this->logger->error('Project source discovery failed unexpectedly.', [
                'projectId' => $projectId,
                'error' => $exception->getMessage(),
            ]);
            $result = ProjectDiscoveryResult::error(
                'Unexpected discovery failure.',
                issues: [[
                    'code' => ProjectSourceIssueCode::UnexpectedFailure->value,
                    'message' => 'Unexpected discovery failure.',
                ]],
            );
        }

        // Reload, compare and publish in one short transaction, never during discovery.
        $queuedIndexing = false;
        $this->entityManager->wrapInTransaction(function () use ($projectId, $root, $result, &$queuedIndexing): void {
            $project = $this->entityManager->find(Project::class, $projectId, LockMode::PESSIMISTIC_WRITE);
            if (null === $project) {
                $this->logger->info('Skipping project scan persistence because registration was removed.', [
                    'projectId' => $projectId,
                ]);

                return;
            }

            $currentRoot = '' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath;
            if ($currentRoot !== $root) {
                $this->logger->info('Discarding obsolete project scan after directory rebinding.', ['projectId' => $projectId]);

                return;
            }

            $this->persistResult($project, $result);
            $queuedIndexing = $result->successful;
        });
        $this->projectStatusPublisher->publish($projectId);

        if ($queuedIndexing) {
            $this->projectIndexManager->queueId($projectId);
        }
    }

    private function persistResult(Project $project, ProjectDiscoveryResult $result): void
    {
        $now = $this->clock->now();
        $details = [
            ...$result->details,
            'issues' => $result->issues,
        ];

        if ($result->successful) {
            $project->markReady(
                now: $now,
                databasePath: $result->databasePath,
                sessionsPath: $result->sessionsPath,
                schemaVersion: $result->schemaVersion,
                observationCount: $result->observationCount,
                reflectionCount: $result->reflectionCount,
                sessionCount: $result->sessionCount,
                sourceRevision: $result->sourceRevision,
                sourceChangeCount: $result->sourceChangeCount,
                sourceDetails: $details,
            );
        } else {
            $project->markError(
                now: $now,
                error: $result->error ?? 'Discovery failed.',
                sourceDetails: $details,
                databasePath: $result->databasePath,
                sessionsPath: $result->sessionsPath,
                schemaVersion: $result->schemaVersion,
                observationCount: $result->observationCount,
                reflectionCount: $result->reflectionCount,
                sessionCount: $result->sessionCount,
                sourceRevision: $result->sourceRevision,
                sourceChangeCount: $result->sourceChangeCount,
            );
        }
    }
}
