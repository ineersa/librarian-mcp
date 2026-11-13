<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndexGenerationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndexGenerationRepository::class)]
#[ORM\Table(name: 'index_generations')]
#[ORM\Index(name: 'idx_index_generation_project_active', columns: ['project_id', 'active'])]
#[ORM\Index(name: 'idx_index_generation_project_id', columns: ['project_id', 'id'])]
class IndexGeneration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Project $project;

    #[ORM\Column(type: 'string', length: 64)]
    public private(set) string $signature;

    #[ORM\Column(name: 'source_identity', type: 'string', length: 64)]
    public private(set) string $sourceIdentity;

    #[ORM\Column(name: 'source_path', type: 'string', length: 2048)]
    public private(set) string $sourcePath;

    #[ORM\Column(name: 'root_binding', type: 'string', length: 2048)]
    public private(set) string $rootBinding;

    #[ORM\Column]
    public private(set) bool $active = false;

    #[ORM\Column(type: 'string', length: 20, enumType: IndexState::class)]
    public private(set) IndexState $state = IndexState::Building;

    /**
     * Keyset cursor: last processed kind|source_id during the current scan round.
     */
    #[ORM\Column(name: 'source_cursor', type: 'string', length: 512, nullable: true)]
    public private(set) ?string $sourceCursor = null;

    #[ORM\Column(name: 'scan_round')]
    public private(set) int $scanRound = 0;

    /**
     * Source revision captured when the current scan round started.
     */
    #[ORM\Column(name: 'scan_revision', nullable: true)]
    public private(set) ?int $scanRevision = null;

    /**
     * Last source revision fully committed for this generation.
     */
    #[ORM\Column(name: 'committed_revision', nullable: true)]
    public private(set) ?int $committedRevision = null;

    #[ORM\Column(name: 'parent_count')]
    public private(set) int $parentCount = 0;

    #[ORM\Column(name: 'chunk_count')]
    public private(set) int $chunkCount = 0;

    #[ORM\Column(name: 'started_at')]
    public private(set) \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    public private(set) ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'error_at', nullable: true)]
    public private(set) ?\DateTimeImmutable $errorAt = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    public private(set) ?string $lastError = null;

    #[ORM\Column(name: 'created_at')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        Project $project,
        string $signature,
        string $sourceIdentity,
        string $sourcePath,
        string $rootBinding,
        \DateTimeImmutable $now,
    ) {
        $this->project = $project;
        $this->signature = $signature;
        $this->sourceIdentity = $sourceIdentity;
        $this->sourcePath = $sourcePath;
        $this->rootBinding = $rootBinding;
        $this->startedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function markBuilding(\DateTimeImmutable $now): void
    {
        $this->state = IndexState::Building;
        $this->lastError = null;
        $this->errorAt = null;
        $this->touch($now);
    }

    public function markUpdating(\DateTimeImmutable $now): void
    {
        $this->state = IndexState::Updating;
        $this->lastError = null;
        $this->errorAt = null;
        $this->touch($now);
    }

    public function markReady(\DateTimeImmutable $now): void
    {
        $this->state = IndexState::Ready;
        $this->completedAt = $now;
        $this->lastError = null;
        $this->errorAt = null;
        $this->touch($now);
    }

    public function markError(\DateTimeImmutable $now, string $error): void
    {
        $this->state = IndexState::Error;
        $this->lastError = mb_substr($error, 0, 2000);
        $this->errorAt = $now;
        $this->touch($now);
    }

    public function markObsolete(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->state = IndexState::Obsolete;
        $this->touch($now);
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->touch($now);
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->touch($now);
    }

    public function beginScanRound(int $sourceRevision, \DateTimeImmutable $now): void
    {
        ++$this->scanRound;
        $this->scanRevision = $sourceRevision;
        // Empty string marks the start of the keyset; null means source pass finished.
        $this->sourceCursor = '';
        if (IndexState::Ready === $this->state || IndexState::Error === $this->state) {
            $this->state = $this->active ? IndexState::Updating : IndexState::Building;
        }
        $this->lastError = null;
        $this->errorAt = null;
        $this->touch($now);
    }

    public function advanceCursor(?string $cursor, \DateTimeImmutable $now): void
    {
        $this->sourceCursor = $cursor;
        $this->touch($now);
    }

    public function commitRevision(int $revision, int $parentCount, int $chunkCount, \DateTimeImmutable $now): void
    {
        $this->committedRevision = $revision;
        $this->parentCount = $parentCount;
        $this->chunkCount = $chunkCount;
        $this->sourceCursor = null;
        $this->touch($now);
    }

    public function refreshCounts(int $parentCount, int $chunkCount, \DateTimeImmutable $now): void
    {
        $this->parentCount = $parentCount;
        $this->chunkCount = $chunkCount;
        $this->touch($now);
    }

    public function matchesBinding(string $rootBinding, string $sourceIdentity, string $signature): bool
    {
        return $this->rootBinding === $rootBinding
            && $this->sourceIdentity === $sourceIdentity
            && $this->signature === $signature;
    }
}
