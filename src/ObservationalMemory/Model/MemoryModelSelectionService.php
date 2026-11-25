<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

use App\Entity\ObservationalMemorySelection;
use App\ObservationalMemory\MemorySettings;
use App\Repository\ObservationalMemorySelectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves and persists the shared OM model selection used for newly enqueued work.
 */
final readonly class MemoryModelSelectionService
{
    public function __construct(
        private MemoryModelCatalog $catalog,
        private MemorySettings $baseline,
        private ObservationalMemorySelectionRepository $selections,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Autowire('%env(default::OPENCODE_API_KEY)%')]
        #[\SensitiveParameter]
        private ?string $opencodeApiKey = null,
        #[Autowire('%env(default::ZAI_API_KEY)%')]
        #[\SensitiveParameter]
        private ?string $zaiApiKey = null,
        #[Autowire('%env(default::VLLM_API_KEY)%')]
        #[\SensitiveParameter]
        private ?string $vllmApiKey = null,
    ) {
    }

    public function currentPinned(): PinnedMemorySettings
    {
        $selection = $this->selections->current();
        if (null === $selection) {
            return $this->baselinePinned();
        }

        try {
            return $this->pin(
                $selection->presetId,
                $selection->reasoningLevel,
            );
        } catch (\InvalidArgumentException) {
            // Corrupt/outdated rows fall back to the env baseline rather than failing ingest.
            return $this->baselinePinned();
        }
    }

    public function resolvePinned(?string $presetId, ?string $reasoningLevel, ?int $contextWindow, ?string $model): PinnedMemorySettings
    {
        $presetId = null === $presetId || '' === trim($presetId) ? null : $presetId;
        $reasoningLevel = null === $reasoningLevel || '' === trim($reasoningLevel) ? null : $reasoningLevel;
        if (null === $presetId || null === $reasoningLevel) {
            return $this->baselinePinned($model);
        }

        $level = MemoryReasoningLevel::tryFrom($reasoningLevel)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown OM reasoning level "%s".', $reasoningLevel));

        return $this->pin($presetId, $level, $contextWindow, $model);
    }

    public function save(string $presetId, MemoryReasoningLevel $reasoningLevel): PinnedMemorySettings
    {
        $pinned = $this->pin($presetId, $reasoningLevel);
        $now = $this->clock->now();
        $selection = $this->selections->current();
        if (null === $selection) {
            $selection = new ObservationalMemorySelection(
                $pinned->presetId,
                $pinned->reasoningLevel,
                $pinned->contextWindow,
                $now,
            );
            $this->entityManager->persist($selection);
        } else {
            $selection->replace(
                $pinned->presetId,
                $pinned->reasoningLevel,
                $pinned->contextWindow,
                $now,
            );
        }
        $this->entityManager->flush();

        return $pinned;
    }

    public function hasCredential(MemoryModelPreset $preset): bool
    {
        return null === $preset->apiKeyEnv || null !== $this->credential($preset->apiKeyEnv);
    }

    /**
     * False when the pinned preset exposes only the provider-default level and has no Reasoning selector.
     */
    public function hasSelectableReasoning(PinnedMemorySettings $settings): bool
    {
        $preset = $this->catalog->get($settings->presetId);

        return null !== $preset && \count($preset->reasoningLevels) > 1;
    }

    public function apiKeyFor(PinnedMemorySettings $settings): ?string
    {
        return $this->credential($settings->apiKeyEnv);
    }

    private function baselinePinned(?string $model = null): PinnedMemorySettings
    {
        $preset = $this->catalog->require(MemoryModelCatalog::LOCAL_PRESET_ID);

        return $this->fromPreset(
            $preset,
            $preset->defaultReasoningLevel,
            $this->baseline->contextWindow,
            $model,
        );
    }

    private function pin(string $presetId, MemoryReasoningLevel $reasoningLevel, ?int $contextWindow = null, ?string $model = null): PinnedMemorySettings
    {
        $preset = $this->catalog->require($presetId);
        if (!$preset->supports($reasoningLevel)) {
            throw new \InvalidArgumentException(\sprintf('Reasoning level "%s" is not available for "%s".', $reasoningLevel->value, $presetId));
        }
        $contextWindow ??= $preset->defaultContextWindow;
        if ($contextWindow < 1 || $contextWindow > $preset->maxContextWindow) {
            throw new \InvalidArgumentException(\sprintf('OM context window must be between 1 and %d for "%s".', $preset->maxContextWindow, $presetId));
        }
        $this->assertCredentialsAvailable($preset);

        return $this->fromPreset($preset, $reasoningLevel, $contextWindow, $model);
    }

    private function credential(?string $keyEnv): ?string
    {
        $key = match ($keyEnv) {
            MemoryModelCatalog::ENV_OPENCODE_API_KEY => $this->opencodeApiKey,
            MemoryModelCatalog::ENV_ZAI_API_KEY => $this->zaiApiKey,
            MemoryModelCatalog::ENV_VLLM_API_KEY => $this->vllmApiKey,
            default => null,
        };

        return '' === trim($key ?? '') ? null : $key;
    }

    private function assertCredentialsAvailable(MemoryModelPreset $preset): void
    {
        if ($this->hasCredential($preset)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf('Preset "%s" requires %s in the environment.', $preset->id, $preset->apiKeyEnv ?? 'an API key'));
    }

    private function fromPreset(
        MemoryModelPreset $preset,
        MemoryReasoningLevel $reasoningLevel,
        int $contextWindow,
        ?string $model = null,
    ): PinnedMemorySettings {
        return new PinnedMemorySettings(
            presetId: $preset->id,
            model: $model ?? $preset->reference(),
            reasoningLevel: $reasoningLevel,
            contextWindow: $contextWindow,
            thinkingFormat: $preset->thinkingFormat,
            baseUrl: $preset->baseUrl,
            completionsPath: $preset->completionsPath,
            apiKeyEnv: $preset->apiKeyEnv,
            supportsTrueOff: $preset->supportsTrueOff,
            observerContextWindowRatio: $this->baseline->observerContextWindowRatio,
            reflectAfterObservationTokens: $this->baseline->reflectAfterObservationTokens,
            observationsMaxTokens: $this->baseline->observationsMaxTokens,
            rendererVersion: $this->baseline->rendererVersion,
            observerSchemaVersion: $this->baseline->observerSchemaVersion,
            reflectorSchemaVersion: $this->baseline->reflectorSchemaVersion,
        );
    }
}
