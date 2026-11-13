<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndexedMemoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndexedMemoryRepository::class)]
#[ORM\Table(name: 'indexed_memories')]
#[ORM\UniqueConstraint(name: 'uniq_indexed_memory_generation_kind_source', columns: ['generation_id', 'kind', 'source_id'])]
#[ORM\Index(name: 'idx_indexed_memory_generation_round', columns: ['generation_id', 'last_seen_round'])]
class IndexedMemory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: IndexGeneration::class)]
    #[ORM\JoinColumn(name: 'generation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public private(set) IndexGeneration $generation;

    #[ORM\Column(type: 'string', length: 32)]
    public private(set) string $kind;

    #[ORM\Column(name: 'source_id', type: 'text')]
    public private(set) string $sourceId;

    #[ORM\Column(name: 'content_signature', type: 'string', length: 64)]
    public private(set) string $contentSignature;

    /** @var list<string> */
    #[ORM\Column(name: 'chunk_ids', type: 'json')]
    public private(set) array $chunkIds;

    #[ORM\Column(name: 'last_seen_round')]
    public private(set) int $lastSeenRound;

    /** @param list<string> $chunkIds */
    public function __construct(IndexGeneration $generation, string $kind, string $sourceId, string $contentSignature, array $chunkIds, int $lastSeenRound)
    {
        $this->generation = $generation;
        $this->kind = $kind;
        $this->sourceId = $sourceId;
        $this->replaceContent($contentSignature, $chunkIds, $lastSeenRound);
    }

    /** @param list<string> $chunkIds */
    public function replaceContent(string $contentSignature, array $chunkIds, int $lastSeenRound): void
    {
        $this->contentSignature = $contentSignature;
        $this->chunkIds = array_values($chunkIds);
        $this->lastSeenRound = $lastSeenRound;
    }
}
