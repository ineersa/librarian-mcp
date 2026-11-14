<?php

declare(strict_types=1);

namespace App\Index\Status;

use App\Entity\IndexGeneration;
use App\Entity\IndexState;
use App\Entity\Project;
use App\Entity\ProjectHealth;

/**
 * Read-model for project source/index status presentation.
 */
final readonly class ProjectIndexStatus
{
    public const string STATUS_NONE = 'none';
    public const string STATUS_STALE = 'stale';
    public const string AUTOMATIC_INDEXING_HELP = 'Automatic indexing follows source discovery on each scheduled check. Unchanged memories are skipped. Check for updates is optional.';

    public function __construct(
        public int $projectId,
        public ProjectHealth $health,
        public ?string $sourceError,
        public ?IndexGeneration $latest,
        public ?IndexGeneration $active,
        public string $statusLabel,
        public string $statusValue,
        public bool $pending,
        public bool $stale,
        public bool $canCheckUpdates,
        public ?string $error,
        public ?\DateTimeImmutable $lastIndexAt,
        public int $indexedMemoryCount,
        public string $automaticIndexingHelp = self::AUTOMATIC_INDEXING_HELP,
    ) {
    }

    public static function fromProject(Project $project, ?IndexGeneration $latest, ?IndexGeneration $active): self
    {
        if (null === $project->id) {
            throw new \InvalidArgumentException('Project id is required for status presentation.');
        }

        $pending = null !== $latest && $latest->state->isInProgress();
        $stale = false;
        if (
            null !== $active
            && null !== $project->sourceRevision
            && null !== $active->committedRevision
            && $active->committedRevision < $project->sourceRevision
        ) {
            $stale = true;
        }

        $statusValue = self::STATUS_NONE;
        $statusLabel = $project->health->isReady() ? 'Waiting for first index' : 'Waiting for source discovery';
        $error = null;
        $indexedMemoryCount = 0;

        if (null !== $latest) {
            $statusValue = $latest->state->value;
            $error = $latest->lastError;
            $indexedMemoryCount = $latest->parentCount;
            $statusLabel = match ($latest->state) {
                IndexState::Building => 'Indexing memories',
                IndexState::Updating => 'Updating index',
                IndexState::Ready => 'Indexed',
                IndexState::Error => 'Indexing error',
                IndexState::Obsolete => 'Index obsolete',
            };
            if ($stale && $latest->state->isUsable() && !$pending) {
                $statusLabel = 'Updates available';
                $statusValue = self::STATUS_STALE;
            }
        }

        $discoveryBusy = $project->health->isPending() || $project->health->isScanning();
        $canCheckUpdates = !$discoveryBusy && !$pending && $project->health->isReady();

        return new self(
            projectId: $project->id,
            health: $project->health,
            sourceError: $project->lastError,
            latest: $latest,
            active: $active,
            statusLabel: $statusLabel,
            statusValue: $statusValue,
            pending: $pending,
            stale: $stale,
            canCheckUpdates: $canCheckUpdates,
            error: $error,
            lastIndexAt: $project->lastSuccessfulIndexAt,
            indexedMemoryCount: $indexedMemoryCount,
        );
    }
}
