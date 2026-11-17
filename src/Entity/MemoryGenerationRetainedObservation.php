<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_generation_retained_observation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_generation_retained_observations')]
#[ORM\UniqueConstraint(name: 'uniq_om_generation_retained', columns: ['generation_id', 'observation_id'])]
#[ORM\UniqueConstraint(name: 'uniq_om_generation_retained_position', columns: ['generation_id', 'position'])]
class MemoryGenerationRetainedObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemoryGeneration::class)]
    #[ORM\JoinColumn(name: 'generation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemoryGeneration $generation;

    #[ORM\Column(name: 'observation_id', type: 'string', length: 64)]
    public private(set) string $observationId;

    #[ORM\Column]
    public private(set) int $position;

    private function __construct()
    {
    }
}
