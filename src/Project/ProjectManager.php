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
        private readonly ProjectPathNormalizer $pathNormalizer,
        private readonly GitOriginNormalizer $gitOriginNormalizer,
    ) {
    }

    /**
     * Normalize identity fields and persist them before queueing discovery.
     */
    public function prepareIdentity(Project $project): void
    {
        $name = trim($project->name);
        $rootPath = trim($project->rootPath);
        $canonical = $this->pathNormalizer->canonicalizeRoot($rootPath);
        if (null === $canonical) {
            // Leave uniqueness fields empty-ish; form/root validators report the path error.
            $canonical = $rootPath;
        }

        $origin = null;
        if (is_dir($canonical)) {
            $origin = $this->gitOriginNormalizer->detectNormalizedOrigin($canonical);
            if ('' === $origin) {
                $origin = null;
            }
        }

        $project->assignIdentity(
            name: $name,
            nameNormalized: $this->pathNormalizer->normalizeName($name),
            rootPath: $rootPath,
            rootCanonical: $canonical,
            repositoryOrigin: $origin,
            now: $this->clock->now(),
        );
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
