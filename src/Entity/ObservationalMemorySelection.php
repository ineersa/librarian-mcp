<?php

declare(strict_types=1);

namespace App\Entity;

use App\ObservationalMemory\Model\MemoryReasoningLevel;
use App\Repository\ObservationalMemorySelectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton non-secret OM provider/model selection for new work.
 *
 * Auto-increment primary key with a unique named slot keeps one active row.
 */
#[ORM\Entity(repositoryClass: ObservationalMemorySelectionRepository::class)]
#[ORM\Table(name: 'observational_memory_selection')]
#[ORM\UniqueConstraint(name: 'uniq_om_selection_slot', columns: ['slot'])]
class ObservationalMemorySelection
{
    public const string ACTIVE_SLOT = 'active';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    public private(set) string $slot = self::ACTIVE_SLOT;

    #[ORM\Column(type: 'string', length: 128)]
    public string $presetId;

    #[ORM\Column(type: 'string', length: 16, enumType: MemoryReasoningLevel::class)]
    public MemoryReasoningLevel $reasoningLevel;

    #[ORM\Column]
    public int $contextWindow;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        string $presetId,
        MemoryReasoningLevel $reasoningLevel,
        int $contextWindow,
        \DateTimeImmutable $now,
        string $slot = self::ACTIVE_SLOT,
    ) {
        $this->slot = $slot;
        $this->presetId = $presetId;
        $this->reasoningLevel = $reasoningLevel;
        $this->contextWindow = $contextWindow;
        $this->updatedAt = $now;
    }

    public function replace(
        string $presetId,
        MemoryReasoningLevel $reasoningLevel,
        int $contextWindow,
        \DateTimeImmutable $now,
    ): void {
        $this->presetId = $presetId;
        $this->reasoningLevel = $reasoningLevel;
        $this->contextWindow = $contextWindow;
        $this->updatedAt = $now;
    }
}
