<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_observation; observation_id keeps the OM content-hash identity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_observations')]
#[ORM\UniqueConstraint(name: 'uniq_om_observation_id', columns: ['memory_session_id', 'observation_id'])]
#[ORM\Index(name: 'idx_om_observation_lookup', columns: ['observation_id'])]
#[ORM\Index(name: 'idx_om_observation_session_timestamp', columns: ['memory_session_id', 'timestamp', 'observation_id'])]
#[ORM\Index(name: 'idx_om_observation_session_range', columns: ['memory_session_id', 'source_start_seq', 'source_end_seq'])]
class MemoryObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorySession::class)]
    #[ORM\JoinColumn(name: 'memory_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemorySession $session;

    #[ORM\Column(name: 'observation_id', type: 'string', length: 64)]
    public private(set) string $observationId;

    #[ORM\Column(name: 'boundary_key', type: 'string', length: 64)]
    public private(set) string $boundaryKey;

    #[ORM\Column(name: 'source_start_seq')]
    public private(set) int $sourceStartSeq;

    #[ORM\Column(name: 'source_end_seq')]
    public private(set) int $sourceEndSeq;

    #[ORM\Column(name: 'source_refs_json', type: 'text')]
    public private(set) string $sourceRefsJson;

    #[ORM\Column(type: 'text')]
    public private(set) string $content;

    #[ORM\Column(name: 'content_hash', type: 'string', length: 64)]
    public private(set) string $contentHash;

    #[ORM\Column(type: 'string', length: 16)]
    public private(set) string $relevance;

    /** Local minute stamp `YYYY-MM-DD HH:MM`, as recorded by the Observer. */
    #[ORM\Column(type: 'string', length: 16)]
    public private(set) string $timestamp;

    #[ORM\Column(name: 'token_count')]
    public private(set) int $tokenCount;

    #[ORM\Column(name: 'observer_model', type: 'string', length: 255)]
    public private(set) string $observerModel;

    #[ORM\Column(name: 'observer_schema_version', type: 'string', length: 32)]
    public private(set) string $observerSchemaVersion;

    /** ISO-8601 UTC string, as in OM. */
    #[ORM\Column(name: 'created_at', type: 'string', length: 64)]
    public private(set) string $createdAt;

    private function __construct()
    {
    }
}
