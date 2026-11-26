<?php

declare(strict_types=1);

namespace App\Entity;

use App\Index\Model\ModelEndpointMode;
use App\Repository\LibrarianSettingsRepository;
use App\Validator\UsableModelEndpoints;
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
#[UsableModelEndpoints]
class LibrarianSettings
{
    public const string ACTIVE_SLOT = 'active';
    /** Upper bound of the 32-bit integer columns. */
    public const int MAX_TOKENS = 2_147_483_647;
    public const int URL_LENGTH = 2048;
    public const int MODEL_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    public private(set) string $slot = self::ACTIVE_SLOT;

    /** Claude Code sessions: Reflector runs once the working set exceeds this many tokens. */
    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_TOKENS)]
    public int $claudeReflectAfterObservationTokens;

    /** Claude Code sessions: Dropper target for the working set after a reflection. */
    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_TOKENS)]
    #[Assert\LessThan(propertyPath: 'claudeReflectAfterObservationTokens', message: 'Observations max must be lower than reflect after, or the Reflector reruns after every observe job.')]
    public int $claudeObservationsMaxTokens;

    /** Hatfield sessions: Reflector runs once the working set exceeds this many tokens. */
    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_TOKENS)]
    public int $hatfieldReflectAfterObservationTokens;

    /** Hatfield sessions: Dropper target for the working set after a reflection. */
    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(self::MAX_TOKENS)]
    #[Assert\LessThan(propertyPath: 'hatfieldReflectAfterObservationTokens', message: 'Observations max must be lower than reflect after, or the Reflector reruns after every observe job.')]
    public int $hatfieldObservationsMaxTokens;

    /** Embeddings server. API URL/model left empty use the CODERANK_URL/CODERANK_MODEL env defaults. */
    #[ORM\Column(length: 16, enumType: ModelEndpointMode::class, options: ['default' => ModelEndpointMode::Api->value])]
    public ModelEndpointMode $embeddingMode = ModelEndpointMode::Api;

    #[ORM\Column(length: self::URL_LENGTH, nullable: true)]
    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: self::URL_LENGTH)]
    public ?string $embeddingApiUrl = null;

    #[ORM\Column(length: self::MODEL_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MODEL_LENGTH)]
    public ?string $embeddingApiModel = null;

    /** Reranker server. API URL/model left empty use the BGE_URL/BGE_MODEL env defaults. */
    #[ORM\Column(length: 16, enumType: ModelEndpointMode::class, options: ['default' => ModelEndpointMode::Api->value])]
    public ModelEndpointMode $rerankerMode = ModelEndpointMode::Api;

    #[ORM\Column(length: self::URL_LENGTH, nullable: true)]
    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: self::URL_LENGTH)]
    public ?string $rerankerApiUrl = null;

    #[ORM\Column(length: self::MODEL_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MODEL_LENGTH)]
    public ?string $rerankerApiModel = null;

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
