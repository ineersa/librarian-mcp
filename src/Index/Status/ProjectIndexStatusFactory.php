<?php

declare(strict_types=1);

namespace App\Index\Status;

use App\Entity\IndexGeneration;
use App\Entity\Project;
use App\Repository\IndexGenerationRepository;

/**
 * Builds and request-caches project index status views without N+1 lookups.
 */
final class ProjectIndexStatusFactory
{
    /** @var array<int, ProjectIndexStatus> */
    private array $cache = [];

    public function __construct(
        private readonly IndexGenerationRepository $indexGenerationRepository,
    ) {
    }

    public function forProject(Project $project): ProjectIndexStatus
    {
        if (null === $project->id) {
            throw new \InvalidArgumentException('Project id is required for status presentation.');
        }

        if (isset($this->cache[$project->id])) {
            return $this->cache[$project->id];
        }

        $latest = $this->indexGenerationRepository->findLatestForProject($project->id);
        $active = $this->indexGenerationRepository->findActiveForProject($project->id);
        $status = ProjectIndexStatus::fromProject($project, $latest, $active);
        $this->cache[$project->id] = $status;

        return $status;
    }

    /**
     * @param list<Project> $projects
     */
    public function warm(array $projects): void
    {
        $ids = [];
        $byId = [];
        foreach ($projects as $project) {
            if (null === $project->id) {
                continue;
            }
            $ids[] = $project->id;
            $byId[$project->id] = $project;
        }
        if ([] === $ids) {
            return;
        }

        $latestByProject = $this->indexGenerationRepository->findLatestByProjectIds($ids);
        $activeByProject = $this->indexGenerationRepository->findActiveByProjectIds($ids);
        foreach ($byId as $projectId => $project) {
            $this->cache[$projectId] = ProjectIndexStatus::fromProject(
                $project,
                $latestByProject[$projectId] ?? null,
                $activeByProject[$projectId] ?? null,
            );
        }
    }

    public function forget(int $projectId): void
    {
        unset($this->cache[$projectId]);
    }

    /**
     * Reloads one project status after committed worker updates.
     */
    public function refresh(Project $project): ProjectIndexStatus
    {
        if (null === $project->id) {
            throw new \InvalidArgumentException('Project id is required for status presentation.');
        }

        $this->forget($project->id);

        return $this->forProject($project);
    }
}
