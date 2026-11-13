<?php

declare(strict_types=1);

namespace App\Index;

use App\Entity\Project;
use App\Index\Store\IndexStoreFactory;
use App\Message\IndexProjectMessage;
use App\Repository\IndexGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Queues bounded project indexing with queue-only Messenger deduplication.
 */
final class ProjectIndexManager
{
    public const string DEDUPE_PREFIX = 'project-index:';
    public const string TICK_DEDUPE_KEY = 'project-index-tick';
    public const float DEDUPE_TTL_SECONDS = 3600.0;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly IndexGenerationRepository $generationRepository,
        private readonly IndexStoreFactory $storeFactory,
        private readonly LockFactory $indexExecutionLockFactory,
    ) {
    }

    public function queue(Project $project): void
    {
        if (null === $project->id) {
            throw new \LogicException('Cannot queue indexing for an unsaved project.');
        }

        $this->queueId($project->id);
    }

    public function queueId(int $projectId): void
    {
        if ($projectId < 1) {
            throw new \InvalidArgumentException('Project id must be positive.');
        }

        $this->messageBus->dispatch(
            new IndexProjectMessage($projectId),
            [
                new DeduplicateStamp(
                    self::DEDUPE_PREFIX.$projectId,
                    self::DEDUPE_TTL_SECONDS,
                    true,
                ),
            ],
        );
    }

    /**
     * Removes Librarian-owned index stores and the project registration.
     *
     * Holds the same execution flock used by ProjectIndexer so deletion cannot
     * race an in-flight checkpoint. Source project files are never touched.
     */
    public function removeRegistration(Project $project): void
    {
        if (null === $project->id) {
            throw new \LogicException('Cannot remove an unsaved project registration.');
        }

        $projectId = $project->id;
        $lock = $this->indexExecutionLockFactory->createLock('project-index-exec:'.$projectId, 600.0);
        if (!$lock->acquire(true)) {
            throw IndexException::sanitized('Unable to acquire project index lock for registration removal.');
        }

        try {
            $generations = $this->generationRepository->findBy(
                ['project' => $projectId],
                ['id' => 'ASC'],
            );

            foreach ($generations as $generation) {
                if (null === $generation->id) {
                    continue;
                }
                $this->storeFactory->removeGeneration($projectId, $generation->id);
            }

            $managed = $this->entityManager->find(Project::class, $projectId);
            if (null !== $managed) {
                $this->entityManager->remove($managed);
                $this->entityManager->flush();
            }
        } catch (IndexException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw IndexException::sanitized('Failed to remove project index registration.', $exception);
        } finally {
            $lock->release();
        }
    }
}
