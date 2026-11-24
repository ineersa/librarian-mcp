<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * Fixed OM provider/model catalog for EasyAdmin and runtime resolution.
 *
 * OpenCode Go IDs and reasoning levels follow the installed Hatfield catalog; Z.ai exposes GLM 5.3 Flash only.
 */
final class MemoryModelCatalog
{
    public const string LOCAL_PRESET_ID = 'llama_cpp/flash';

    /**
     * Hardcoded local llama.cpp context window (tokens). Deliberately not env-overridable.
     */
    public const int LOCAL_CONTEXT_WINDOW = 200_000;

    public const int CLOUD_DEFAULT_CONTEXT_WINDOW = 200_000;

    public const string OPENCODE_GO_BASE_URL = 'https://opencode.ai/zen/go/v1';
    public const string OPENCODE_GO_COMPLETIONS_PATH = '/chat/completions';
    public const string ZAI_BASE_URL = 'https://api.z.ai/api/coding/paas/v4';
    public const string ZAI_COMPLETIONS_PATH = '/chat/completions';

    public const string ENV_OPENCODE_API_KEY = 'OPENCODE_API_KEY';
    public const string ENV_ZAI_API_KEY = 'ZAI_API_KEY';

    /**
     * @var array<string, MemoryModelPreset>|null
     */
    private ?array $presets = null;

    public function __construct(
        private readonly string $localModel,
        private readonly string $localModelUrl,
        private readonly ?string $localThinkingFormat,
    ) {
    }

    public function get(string $presetId): ?MemoryModelPreset
    {
        return $this->all()[$presetId] ?? null;
    }

    public function require(string $presetId): MemoryModelPreset
    {
        return $this->get($presetId)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown OM model preset "%s".', $presetId));
    }

    /**
     * @return array<string, MemoryModelPreset>
     */
    public function all(): array
    {
        return $this->presets ??= $this->build();
    }

    /**
     * Grouped ChoiceType values: provider label => [choice label => preset id].
     *
     * @return array<string, array<string, string>>
     */
    public function groupedChoices(): array
    {
        $choices = [];
        foreach ($this->all() as $preset) {
            $choices[$preset->provider->label()][$preset->label] = $preset->id;
        }

        return $choices;
    }

    /**
     * @return array<string, MemoryModelPreset>
     */
    private function build(): array
    {
        $localModelId = $this->providerModelName($this->localModel);
        $localThinkingFormat = $this->resolveLocalThinkingFormat();
        $local = new MemoryModelPreset(
            id: self::LOCAL_PRESET_ID,
            provider: MemoryModelProvider::LlamaCpp,
            modelId: $localModelId,
            label: 'Local llama.cpp ('.$localModelId.')',
            thinkingFormat: $localThinkingFormat,
            defaultContextWindow: self::LOCAL_CONTEXT_WINDOW,
            maxContextWindow: self::LOCAL_CONTEXT_WINDOW,
            reasoningLevels: [MemoryReasoningLevel::Default],
            defaultReasoningLevel: MemoryReasoningLevel::Default,
            supportsTrueOff: false,
            apiKeyEnv: null,
            baseUrl: $this->localModelUrl,
            completionsPath: str_ends_with(rtrim($this->localModelUrl, '/'), '/v1') ? '/chat/completions' : '/v1/chat/completions',
            configuredModelReference: $this->localModel,
        );

        $lowHighMaxLevels = [MemoryReasoningLevel::Low, MemoryReasoningLevel::High, MemoryReasoningLevel::Max];
        $museSparkLevels = [MemoryReasoningLevel::Minimal, MemoryReasoningLevel::Low, MemoryReasoningLevel::Medium, MemoryReasoningLevel::High, MemoryReasoningLevel::Xhigh];
        $spaceBunnyLevels = [MemoryReasoningLevel::Low, MemoryReasoningLevel::Medium, MemoryReasoningLevel::High, MemoryReasoningLevel::Xhigh, MemoryReasoningLevel::Max];
        $cloud = [
            new MemoryModelPreset(
                id: 'opencode-go/deepseek-v4.1-flash',
                provider: MemoryModelProvider::OpencodeGo,
                modelId: 'deepseek-v4.1-flash',
                label: 'DeepSeek V4.1 Flash',
                thinkingFormat: MemoryThinkingFormat::Deepseek,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $lowHighMaxLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_OPENCODE_API_KEY,
                baseUrl: self::OPENCODE_GO_BASE_URL,
                completionsPath: self::OPENCODE_GO_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'opencode-go/muse-spark-1.3-contributor',
                provider: MemoryModelProvider::OpencodeGo,
                modelId: 'muse-spark-1.3-contributor',
                label: 'Muse Spark 1.3 Contributor',
                thinkingFormat: MemoryThinkingFormat::ChatGpt,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $museSparkLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_OPENCODE_API_KEY,
                baseUrl: self::OPENCODE_GO_BASE_URL,
                completionsPath: self::OPENCODE_GO_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'opencode-go/space-bunny-free',
                provider: MemoryModelProvider::OpencodeGo,
                modelId: 'space-bunny-free',
                label: 'Space Bunny Free',
                thinkingFormat: MemoryThinkingFormat::ChatGpt,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $spaceBunnyLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_OPENCODE_API_KEY,
                baseUrl: self::OPENCODE_GO_BASE_URL,
                completionsPath: self::OPENCODE_GO_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'opencode-go/longcat-2.5-preview-free',
                provider: MemoryModelProvider::OpencodeGo,
                modelId: 'longcat-2.5-preview-free',
                label: 'LongCat 2.5 Preview Free',
                thinkingFormat: MemoryThinkingFormat::None,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: [MemoryReasoningLevel::Default],
                defaultReasoningLevel: MemoryReasoningLevel::Default,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_OPENCODE_API_KEY,
                baseUrl: self::OPENCODE_GO_BASE_URL,
                completionsPath: self::OPENCODE_GO_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'zai/glm-5.3-flash',
                provider: MemoryModelProvider::Zai,
                modelId: 'glm-5.3-flash',
                label: 'GLM 5.3 Flash',
                thinkingFormat: MemoryThinkingFormat::Zai,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $lowHighMaxLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_ZAI_API_KEY,
                baseUrl: self::ZAI_BASE_URL,
                completionsPath: self::ZAI_COMPLETIONS_PATH,
            ),
        ];

        $presets = [$local->id => $local];
        foreach ($cloud as $preset) {
            $presets[$preset->id] = $preset;
        }

        return $presets;
    }

    private function resolveLocalThinkingFormat(): MemoryThinkingFormat
    {
        $format = null === $this->localThinkingFormat || '' === trim($this->localThinkingFormat)
            ? MemoryThinkingFormat::None->value
            : trim($this->localThinkingFormat);
        $resolved = MemoryThinkingFormat::tryFrom($format);
        if (null === $resolved) {
            throw new \InvalidArgumentException(\sprintf('Unsupported OM thinking format "%s".', $format));
        }

        return $resolved;
    }

    private function providerModelName(string $model): string
    {
        $model = trim($model);
        if ('' === $model || !str_contains($model, '/')) {
            throw new \InvalidArgumentException('OM model must be an exact provider/model reference (provider/model).');
        }

        return substr($model, strpos($model, '/') + 1);
    }
}
