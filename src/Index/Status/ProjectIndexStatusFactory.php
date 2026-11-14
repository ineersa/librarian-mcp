<?php

declare(strict_types=1);

namespace App\Index\Status;

use App\Entity\Project;
use App\Repository\IndexGenerationRepository;

/**
 * Builds fresh project index status views, in bulk for the list page.
 */
final class ProjectIndexStatusFactory
{
    public function __construct(
        private readonly IndexGenerationRepository $indexGenerationRepository,
    ) {
    }

    public function forProject(Project $project): ProjectIndexStatus
    {
        if (null === $project->id) {
            throw new \InvalidArgumentException('Project id is required for status presentation.');
        }

        $latest = $this->indexGenerationRepository->findLatestForProject($project->id);
        $active = $this->indexGenerationRepository->findActiveForProject($project->id);

        return ProjectIndexStatus::fromProject($project, $latest, $active);
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<int, ProjectIndexStatus>
     */
    public function forProjects(array $projects): array
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
            return [];
        }

        $latestByProject = $this->indexGenerationRepository->findLatestByProjectIds($ids);
        $activeByProject = $this->indexGenerationRepository->findActiveByProjectIds($ids);
        $statuses = [];
        foreach ($byId as $projectId => $project) {
            $statuses[$projectId] = ProjectIndexStatus::fromProject(
                $project,
                $latestByProject[$projectId] ?? null,
                $activeByProject[$projectId] ?? null,
            );
        }

        return $statuses;
    }
}
