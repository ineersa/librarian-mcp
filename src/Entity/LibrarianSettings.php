<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LibrarianSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Singleton operator settings edited in the admin. Read at job time, never carried in messages.
 *
 * Auto-increment primary key with a unique named slot keeps one row.
 */
#[ORM\Entity(repositoryClass: LibrarianSettingsRepository::class)]
#[ORM\Table(name: 'librarian_settings')]
#[ORM\UniqueConstraint(name: 'uniq_librarian_settings_slot', columns: ['slot'])]
class LibrarianSettings
{
    public const string ACTIVE_SLOT = 'active';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    public private(set) string $slot = self::ACTIVE_SLOT;

    /** Claude Code sessions: Reflector runs once the working set exceeds this many tokens. */
    #[ORM\Column]
    #[Assert\Positive]
    public int $claudeReflectAfterObservationTokens;

    /** Claude Code sessions: Dropper target for the working set after a reflection. */
    #[ORM\Column]
    #[Assert\Positive]
    public int $claudeObservationsMaxTokens;

    /** Hatfield sessions: Reflector runs once the working set exceeds this many tokens. */
    #[ORM\Column]
    #[Assert\Positive]
    public int $hatfieldReflectAfterObservationTokens;

    /** Hatfield sessions: Dropper target for the working set after a reflection. */
    #[ORM\Column]
    #[Assert\Positive]
    public int $hatfieldObservationsMaxTokens;

    public function __construct(int $reflectAfterObservationTokens, int $observationsMaxTokens)
    {
        $this->claudeReflectAfterObservationTokens = $reflectAfterObservationTokens;
        $this->claudeObservationsMaxTokens = $observationsMaxTokens;
        $this->hatfieldReflectAfterObservationTokens = $reflectAfterObservationTokens;
        $this->hatfieldObservationsMaxTokens = $observationsMaxTokens;
    }

    /** @return array{reflectAfterObservationTokens: int, observationsMaxTokens: int} */
    public function tokenLimitsFor(MemorySourceKind $sourceKind): array
    {
        return match ($sourceKind) {
            MemorySourceKind::ClaudeCode => [
                'reflectAfterObservationTokens' => $this->claudeReflectAfterObservationTokens,
                'observationsMaxTokens' => $this->claudeObservationsMaxTokens,
            ],
            MemorySourceKind::Hatfield => [
                'reflectAfterObservationTokens' => $this->hatfieldReflectAfterObservationTokens,
                'observationsMaxTokens' => $this->hatfieldObservationsMaxTokens,
            ],
        };
    }
}
