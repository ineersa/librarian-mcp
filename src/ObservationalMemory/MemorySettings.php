<?php

declare(strict_types=1);

namespace App\ObservationalMemory;

/**
 * Librarian-side port of OM OmSettings plus the single model's provider binding.
 *
 * `model` stays the exact Hatfield `provider/model` reference because it is an
 * input of threshold generation identity (byte-identical IDs with imported data).
 */
final readonly class MemorySettings
{
    public const string DEFAULT_RENDERER_VERSION = '1';
    public const string DEFAULT_OBSERVER_SCHEMA_VERSION = '1';
    public const string DEFAULT_REFLECTOR_SCHEMA_VERSION = '1';

    /** Closest Hatfield mapping of Pi agentMaxTurns=16. */
    public const int DEFAULT_AGENT_MAX_TOOL_CALLS = 16;

    /** Per-HTTP-request max_duration for OM model calls. */
    public const int AGENT_HTTP_MAX_DURATION_SECONDS = 300;

    /** Hatfield catalog `compatibility.thinking_format` value for llama.cpp chat templates. */
    public const string THINKING_FORMAT_LLAMA_CPP = 'llama_cpp';

    public string $providerModelName;

    public function __construct(
        public string $model,
        public string $modelUrl,
        public int $contextWindow,
        public ?string $thinkingFormat,
        public float $observerContextWindowRatio,
        public int $reflectAfterObservationTokens,
        public int $observationsMaxTokens,
        public string $rendererVersion = self::DEFAULT_RENDERER_VERSION,
        public string $observerSchemaVersion = self::DEFAULT_OBSERVER_SCHEMA_VERSION,
        public string $reflectorSchemaVersion = self::DEFAULT_REFLECTOR_SCHEMA_VERSION,
    ) {
        $model = trim($this->model);
        if ('' === $model || str_starts_with($model, '@') || !str_contains($model, '/')) {
            throw new \InvalidArgumentException('OM model must be an exact provider/model reference (provider/model).');
        }
        if ('' === trim($this->modelUrl)) {
            throw new \InvalidArgumentException('OM model URL is required.');
        }
        if ($this->contextWindow <= 0) {
            throw new \InvalidArgumentException('OM model context window must be positive.');
        }
        if (null !== $this->thinkingFormat && self::THINKING_FORMAT_LLAMA_CPP !== $this->thinkingFormat) {
            throw new \InvalidArgumentException('OM thinking format must be empty or "llama_cpp".');
        }
        if ($this->observerContextWindowRatio <= 0.0 || $this->observerContextWindowRatio >= 1.0) {
            throw new \InvalidArgumentException('context_window_ratio must satisfy 0 < ratio < 1.');
        }
        if ($this->reflectAfterObservationTokens < 1 || $this->observationsMaxTokens < 1) {
            throw new \InvalidArgumentException('OM token thresholds must be positive.');
        }
        $this->providerModelName = substr($model, strpos($model, '/') + 1);
    }

    public function observerEnvelope(): int
    {
        return (int) floor($this->contextWindow * $this->observerContextWindowRatio);
    }
}
