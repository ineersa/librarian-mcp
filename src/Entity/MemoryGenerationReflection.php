<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Port of OM om_generation_reflection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_generation_reflections')]
#[ORM\UniqueConstraint(name: 'uniq_om_generation_reflection', columns: ['generation_id', 'reflection_id'])]
#[ORM\UniqueConstraint(name: 'uniq_om_generation_reflection_position', columns: ['generation_id', 'position'])]
class MemoryGenerationReflection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemoryGeneration::class)]
    #[ORM\JoinColumn(name: 'generation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemoryGeneration $generation;

    #[ORM\Column(name: 'reflection_id', type: 'string', length: 64)]
    public private(set) string $reflectionId;

    #[ORM\Column]
    public private(set) int $position;

    private function __construct()
    {
    }
}
