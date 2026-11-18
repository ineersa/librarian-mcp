<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Byte position in a session's source transcript/journal just after the line holding `seq`.
 *
 * Recorded after Observer coverage reaches `seq`, so later observe and recall reads seek here
 * instead of rescanning from the top. `boundary_hash` fingerprints the bytes just before the
 * offset; a mismatch (rewritten or replaced file) makes readers ignore the checkpoint.
 */
#[ORM\Entity]
#[ORM\Table(name: 'om_source_checkpoints')]
#[ORM\UniqueConstraint(name: 'uniq_om_source_checkpoint_seq', columns: ['memory_session_id', 'seq'])]
class MemorySourceCheckpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MemorySession::class)]
    #[ORM\JoinColumn(name: 'memory_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) MemorySession $session;

    #[ORM\Column(name: 'seq')]
    public private(set) int $seq;

    #[ORM\Column(name: 'byte_offset', type: Types::BIGINT)]
    public private(set) int $byteOffset;

    #[ORM\Column(name: 'boundary_hash', type: Types::STRING, length: 64)]
    public private(set) string $boundaryHash;

    private function __construct()
    {
    }
}
