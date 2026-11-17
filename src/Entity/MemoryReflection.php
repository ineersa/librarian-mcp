<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_reflection; reflection_id keeps the OM content-hash identity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_reflections')]
#[ORM\UniqueConstraint(name: 'uniq_om_reflection_id', columns: ['memory_session_id', 'reflection_id'])]
#[ORM\Index(name: 'idx_om_reflection_lookup', columns: ['reflection_id'])]
#[ORM\Index(name: 'idx_om_reflection_session_created', columns: ['memory_session_id', 'created_at'])]
class MemoryReflection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorySession::class)]
    #[ORM\JoinColumn(name: 'memory_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemorySession $session;

    #[ORM\Column(name: 'reflection_id', type: 'string', length: 64)]
    public private(set) string $reflectionId;

    #[ORM\Column(name: 'observation_set_hash', type: 'string', length: 64)]
    public private(set) string $observationSetHash;

    #[ORM\Column(type: 'text')]
    public private(set) string $content;

    #[ORM\Column(name: 'supporting_observation_ids_json', type: 'text')]
    public private(set) string $supportingObservationIdsJson;

    #[ORM\Column(name: 'token_count')]
    public private(set) int $tokenCount;

    #[ORM\Column(name: 'reflector_model', type: 'string', length: 255)]
    public private(set) string $reflectorModel;

    #[ORM\Column(name: 'reflector_schema_version', type: 'string', length: 32)]
    public private(set) string $reflectorSchemaVersion;

    #[ORM\Column(name: 'created_at', type: 'string', length: 64)]
    public private(set) string $createdAt;

    private function __construct()
    {
    }
}
