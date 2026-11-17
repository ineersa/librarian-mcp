<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_memory_generation (threshold trigger only).
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_memory_generations')]
#[ORM\UniqueConstraint(name: 'uniq_om_generation_id', columns: ['memory_session_id', 'generation_id'])]
#[ORM\Index(name: 'idx_om_generation_session_set_status', columns: ['memory_session_id', 'observation_set_hash', 'status'])]
class MemoryGeneration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorySession::class)]
    #[ORM\JoinColumn(name: 'memory_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemorySession $session;

    /** OM threshold-generation-v1 hash (also the threshold idempotency key). */
    #[ORM\Column(name: 'generation_id', type: 'string', length: 64)]
    public private(set) string $generationId;

    #[ORM\Column(type: 'string', length: 16, enumType: MemoryGenerationStatus::class)]
    public private(set) MemoryGenerationStatus $status;

    #[ORM\Column(name: 'observation_set_hash', type: 'string', length: 64)]
    public private(set) string $observationSetHash;

    #[ORM\Column(name: 'reflector_model', type: 'string', length: 255)]
    public private(set) string $reflectorModel;

    #[ORM\Column(name: 'reflector_schema_version', type: 'string', length: 32)]
    public private(set) string $reflectorSchemaVersion;

    #[ORM\Column(name: 'required_start_seq', nullable: true)]
    public private(set) ?int $requiredStartSeq = null;

    #[ORM\Column(name: 'required_end_seq', nullable: true)]
    public private(set) ?int $requiredEndSeq = null;

    #[ORM\Column(name: 'failure_code', type: 'string', length: 64, nullable: true)]
    public private(set) ?string $failureCode = null;

    #[ORM\Column(name: 'created_at', type: 'string', length: 64)]
    public private(set) string $createdAt;

    #[ORM\Column(name: 'completed_at', type: 'string', length: 64, nullable: true)]
    public private(set) ?string $completedAt = null;

    private function __construct()
    {
    }
}
