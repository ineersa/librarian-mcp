<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Project;
use App\Index\Status\ProjectIndexStatus;
use App\Index\Status\ProjectIndexStatusFactory;
use App\Memory\MemoryErrorCode;
use App\Project\GitOriginNormalizer;
use App\Project\ProjectManager;
use App\Project\ProjectSourceException;
use App\Project\ProjectSourceIssueCode;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Ensures only Librarian-owned registration metadata; discovery remains asynchronous. */
final readonly class EnsureProject
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private ProjectRepository $projects,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
        private ProjectManager $projectManager,
        private ProjectIndexStatusFactory $statusFactory,
        private GitOriginNormalizer $gitOriginNormalizer,
    ) {
    }

    /** @return array<string, mixed> */
    public function ensure(string $canonicalRoot): array
    {
        // Recheck policy before even returning an old registration.
        try {
            $this->gitOriginNormalizer->assertSupportedRoot($canonicalRoot);
        } catch (ProjectSourceException $exception) {
            throw new MemoryToolException(ProjectSourceIssueCode::LinkedWorktreeUnsupported === $exception->issueCode ? MemoryErrorCode::LinkedWorktreeUnsupported : MemoryErrorCode::ProjectRegistrationUnavailable, $exception->getMessage());
        }

        try {
            $existing = $this->projects->findOneBy(['rootCanonical' => $canonicalRoot]);
            if (null !== $existing) {
                return $this->result($existing, false);
            }

            $project = new Project($this->clock->now());
            $project->name = basename($canonicalRoot);
            $project->rootPath = $canonicalRoot;
            $violations = $this->validator->validate($project);
            if (\count($violations) > 0) {
                // Another caller may have registered this root during validation.
                $existing = $this->projects->findOneBy(['rootCanonical' => $canonicalRoot]);
                if (null !== $existing) {
                    return $this->result($existing, false);
                }
                $this->reject($violations);
            }

            try {
                // Validation/Git inspection above must not hold a write transaction.
                $this->entityManager->persist($project);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // The database unique indexes settle races after validation.
                $this->managerRegistry->resetManager();
                $existing = $this->projects->findOneBy(['rootCanonical' => $canonicalRoot]);
                if (null !== $existing) {
                    return $this->result($existing, false);
                }
                $this->reject($this->validator->validate($project));
                throw new MemoryToolException(MemoryErrorCode::ProjectRegistrationConflict, 'A project with this display name or Git repository origin already exists.');
            }

            $this->projectManager->queueScan($project);

            return $this->result($project, true);
        } catch (DatabaseException|MessengerException|LockAcquiringException) {
            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }
            throw new MemoryToolException(MemoryErrorCode::ProjectRegistrationUnavailable, 'Project registration or discovery queueing is temporarily unavailable. Retry later.');
        }
    }

    private function reject(ConstraintViolationListInterface $violations): void
    {
        foreach ($violations as $violation) {
            $code = match ($violation->getCode()) {
                UniqueEntity::NOT_UNIQUE_ERROR => MemoryErrorCode::ProjectRegistrationConflict,
                ProjectSourceIssueCode::GitMetadataUnavailable->value => MemoryErrorCode::ProjectRegistrationUnavailable,
                ProjectSourceIssueCode::LinkedWorktreeUnsupported->value => MemoryErrorCode::LinkedWorktreeUnsupported,
                default => MemoryErrorCode::InvalidProjectContext,
            };
            throw new MemoryToolException($code, (string) $violation->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function result(Project $project, bool $created): array
    {
        $status = $this->statusFactory->forProject($project);
        $active = $status->active;

        return [
            'ok' => true,
            'created' => $created,
            'project' => MemoryContextResolver::provenance($project),
            'source' => ['state' => $project->health->value, 'error' => $project->lastError],
            'index' => [
                'state' => $status->latest?->state->value ?? ProjectIndexStatus::STATUS_NONE,
                'searchable' => null !== $active && $active->state->isUsable() && null !== $active->committedRevision,
            ],
        ];
    }
}
