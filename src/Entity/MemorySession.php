<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One client session whose OM memory Librarian owns.
 *
 * Identity is (project, source kind, session id). The session id is the OM `run_id`
 * used verbatim in identity hashes, so imported Hatfield IDs stay byte-identical.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_sessions')]
#[ORM\UniqueConstraint(name: 'uniq_om_session_identity', columns: ['project_id', 'source_kind', 'session_id'])]
class MemorySession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Project $project;

    #[ORM\Column(name: 'source_kind', type: 'string', length: 32, enumType: MemorySourceKind::class)]
    public private(set) MemorySourceKind $sourceKind;

    #[ORM\Column(name: 'session_id', type: 'string', length: 255)]
    public private(set) string $sessionId;

    /** Provenance only; Librarian never resolves child journals. */
    #[ORM\Column(name: 'parent_session_id', type: 'string', length: 255, nullable: true)]
    public private(set) ?string $parentSessionId = null;

    /** OM om_active_generation, folded into the session row. */
    #[ORM\ManyToOne(targetEntity: MemoryGeneration::class)]
    #[ORM\JoinColumn(name: 'active_generation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public private(set) ?MemoryGeneration $activeGeneration = null;

    #[ORM\Column(name: 'created_at')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(Project $project, MemorySourceKind $sourceKind, string $sessionId, ?string $parentSessionId, \DateTimeImmutable $now)
    {
        $this->project = $project;
        $this->sourceKind = $sourceKind;
        $this->sessionId = $sessionId;
        $this->parentSessionId = $parentSessionId;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }
}
