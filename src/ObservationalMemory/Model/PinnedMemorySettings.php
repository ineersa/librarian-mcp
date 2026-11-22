<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

use App\ObservationalMemory\MemorySettings;

/**
 * Non-secret OM model snapshot carried by observe/reflect jobs.
 */
final readonly class PinnedMemorySettings
{
    public string $providerModelName;

    public function __construct(
        public string $presetId,
        public string $model,
        public MemoryReasoningLevel $reasoningLevel,
        public int $contextWindow,
        public MemoryModelBridge $bridge,
        public MemoryThinkingFormat $thinkingFormat,
        public ?string $baseUrl,
        public string $completionsPath,
        public ?string $apiKeyEnv,
        public bool $supportsTrueOff,
        public float $observerContextWindowRatio,
        public int $reflectAfterObservationTokens,
        public int $observationsMaxTokens,
        public string $rendererVersion = MemorySettings::DEFAULT_RENDERER_VERSION,
        public string $observerSchemaVersion = MemorySettings::DEFAULT_OBSERVER_SCHEMA_VERSION,
        public string $reflectorSchemaVersion = MemorySettings::DEFAULT_REFLECTOR_SCHEMA_VERSION,
    ) {
        $model = trim($this->model);
        if ('' === $model || !str_contains($model, '/')) {
            throw new \InvalidArgumentException('Pinned OM model must be an exact provider/model reference.');
        }
        if ($this->contextWindow < 1) {
            throw new \InvalidArgumentException('Pinned OM context window must be positive.');
        }
        if ($this->observerContextWindowRatio <= 0.0 || $this->observerContextWindowRatio >= 1.0) {
            throw new \InvalidArgumentException('Pinned context_window_ratio must satisfy 0 < ratio < 1.');
        }
        if ($this->reflectAfterObservationTokens < 1 || $this->observationsMaxTokens < 1) {
            throw new \InvalidArgumentException('Pinned OM token thresholds must be positive.');
        }
        $this->providerModelName = substr($model, strpos($model, '/') + 1);
    }

    public function observerEnvelope(): int
    {
        return (int) floor($this->contextWindow * $this->observerContextWindowRatio);
    }

    /**
     * @return array{
     *   presetId: string,
     *   model: string,
     *   reasoningLevel: string,
     *   contextWindow: int
     * }
     */
    public function toMessagePayload(): array
    {
        return [
            'presetId' => $this->presetId,
            'model' => $this->model,
            'reasoningLevel' => $this->reasoningLevel->value,
            'contextWindow' => $this->contextWindow,
        ];
    }
}
