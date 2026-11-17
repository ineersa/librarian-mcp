<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_coverage (Observer chunk/part coverage).
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_observer_coverage')]
#[ORM\UniqueConstraint(name: 'uniq_om_coverage_key', columns: ['memory_session_id', 'coverage_key'])]
#[ORM\UniqueConstraint(name: 'uniq_om_coverage_chunk_part', columns: ['memory_session_id', 'chunk_key', 'part_index', 'renderer_version', 'observer_schema_version'])]
#[ORM\Index(name: 'idx_om_coverage_versions_range', columns: ['memory_session_id', 'renderer_version', 'observer_schema_version', 'source_start_seq', 'source_end_seq'])]
class MemoryObserverCoverage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorySession::class)]
    #[ORM\JoinColumn(name: 'memory_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemorySession $session;

    #[ORM\Column(name: 'coverage_key', type: 'string', length: 64)]
    public private(set) string $coverageKey;

    #[ORM\Column(name: 'boundary_key', type: 'string', length: 64)]
    public private(set) string $boundaryKey;

    #[ORM\Column(name: 'source_start_seq')]
    public private(set) int $sourceStartSeq;

    #[ORM\Column(name: 'source_end_seq')]
    public private(set) int $sourceEndSeq;

    #[ORM\Column(name: 'chunk_key', type: 'string', length: 64)]
    public private(set) string $chunkKey;

    #[ORM\Column(name: 'part_index')]
    public private(set) int $partIndex;

    #[ORM\Column(name: 'part_count')]
    public private(set) int $partCount;

    #[ORM\Column(name: 'source_digest', type: 'string', length: 64)]
    public private(set) string $sourceDigest;

    #[ORM\Column(name: 'part_digest', type: 'string', length: 64)]
    public private(set) string $partDigest;

    #[ORM\Column(name: 'renderer_version', type: 'string', length: 32)]
    public private(set) string $rendererVersion;

    #[ORM\Column(name: 'observer_schema_version', type: 'string', length: 32)]
    public private(set) string $observerSchemaVersion;

    #[ORM\Column(name: 'observation_count')]
    public private(set) int $observationCount;

    #[ORM\Column(name: 'covered_at', type: 'string', length: 64)]
    public private(set) string $coveredAt;

    private function __construct()
    {
    }
}
