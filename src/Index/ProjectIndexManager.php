<?php

declare(strict_types=1);

namespace App\Index;

use App\Entity\Project;
use App\Message\IndexProjectMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Queues bounded project indexing with queue-only Messenger deduplication.
 */
final class ProjectIndexManager
{
    public const string DEDUPE_PREFIX = 'project-index:';
    public const float DEDUPE_TTL_SECONDS = 3600.0;

    public function __construct(
        private readonly MessageBusInterface $messageBus,
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
}
