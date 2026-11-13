<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndexedMemoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndexedMemoryRepository::class)]
#[ORM\Table(name: 'indexed_memories')]
#[ORM\UniqueConstraint(name: 'uniq_indexed_memory_generation_kind_source', columns: ['generation_id', 'kind', 'source_id'])]
#[ORM\Index(name: 'idx_indexed_memory_generation_round', columns: ['generation_id', 'last_seen_round'])]
#[ORM\Index(name: 'idx_indexed_memory_generation_kind_source', columns: ['generation_id', 'kind', 'source_id'])]
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

    #[ORM\Column(name: 'source_id', type: 'string', length: 255)]
    public private(set) string $sourceId;

    #[ORM\Column(name: 'session_id', type: 'string', length: 255)]
    public private(set) string $sessionId;

    /**
     * Canonical memory date as UTC epoch seconds.
     */
    #[ORM\Column(name: 'occurred_at')]
    public private(set) int $occurredAt;

    #[ORM\Column(name: 'content_signature', type: 'string', length: 64)]
    public private(set) string $contentSignature;

    /**
     * Deterministic chunk business IDs for this parent.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'chunk_ids', type: 'json')]
    public private(set) array $chunkIds = [];

    #[ORM\Column(name: 'last_seen_round')]
    public private(set) int $lastSeenRound = 0;

    #[ORM\Column(name: 'created_at')]
    public private(set) \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    public private(set) \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $chunkIds
     */
    public function __construct(
        IndexGeneration $generation,
        string $kind,
        string $sourceId,
        string $sessionId,
        int $occurredAt,
        string $contentSignature,
        array $chunkIds,
        int $lastSeenRound,
        \DateTimeImmutable $now,
    ) {
        $this->generation = $generation;
        $this->kind = $kind;
        $this->sourceId = $sourceId;
        $this->sessionId = $sessionId;
        $this->occurredAt = $occurredAt;
        $this->contentSignature = $contentSignature;
        $this->chunkIds = array_values($chunkIds);
        $this->lastSeenRound = $lastSeenRound;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<string> $chunkIds
     */
    public function replaceContent(
        string $sessionId,
        int $occurredAt,
        string $contentSignature,
        array $chunkIds,
        int $lastSeenRound,
        \DateTimeImmutable $now,
    ): void {
        $this->sessionId = $sessionId;
        $this->occurredAt = $occurredAt;
        $this->contentSignature = $contentSignature;
        $this->chunkIds = array_values($chunkIds);
        $this->lastSeenRound = $lastSeenRound;
        $this->updatedAt = $now;
    }

    public function markSeen(int $round, \DateTimeImmutable $now): void
    {
        $this->lastSeenRound = $round;
        $this->updatedAt = $now;
    }
}
