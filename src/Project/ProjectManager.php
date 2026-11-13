<?php

declare(strict_types=1);

namespace App\Project;

use App\Entity\Project;
use App\Message\ScanProjectMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Owns short registration writes and scan queueing for Project entities.
 */
final class ProjectManager
{
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

        $this->messageBus->dispatch(new ScanProjectMessage($project->id));
    }
}
