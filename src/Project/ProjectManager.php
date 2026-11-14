<?php

declare(strict_types=1);

namespace App\Project;

use App\Entity\Project;
use App\Message\ScanProjectMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Owns short registration writes and scan queueing for Project entities.
 */
final class ProjectManager
{
    public const string SCAN_DEDUPE_PREFIX = 'project-scan:';
    public const float SCAN_DEDUPE_TTL_SECONDS = 3600.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function queueScan(Project $project): void
    {
        if (null === $project->id) {
            throw new \LogicException('Cannot queue a scan for an unsaved project.');
        }

        $project->markPendingScan($this->clock->now());
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ScanProjectMessage($project->id), [new DeduplicateStamp(
            self::SCAN_DEDUPE_PREFIX.$project->id,
            self::SCAN_DEDUPE_TTL_SECONDS,
            true,
        )]);
    }
}
