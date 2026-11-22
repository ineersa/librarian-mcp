<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * Fixed OM provider/model catalog for EasyAdmin and runtime resolution.
 *
 * Mistral IDs follow the installed Hatfield catalog; Z.ai exposes GLM 5.3 Flash only.
 */
final class MemoryModelCatalog
{
    public const string LOCAL_PRESET_ID = 'llama_cpp/flash';
    public const int CLOUD_DEFAULT_CONTEXT_WINDOW = 200_000;

    public const string MISTRAL_BASE_URL = 'https://api.mistral.ai';
    public const string MISTRAL_COMPLETIONS_PATH = '/v1/chat/completions';
    public const string ZAI_BASE_URL = 'https://api.z.ai/api/coding/paas/v4';
    public const string ZAI_COMPLETIONS_PATH = '/chat/completions';

    public const string ENV_MISTRAL_API_KEY = 'MISTRAL_API_KEY';
    public const string ENV_ZAI_API_KEY = 'ZAI_API_KEY';

    /**
     * @var array<string, MemoryModelPreset>|null
     */
    private ?array $presets = null;

    public function __construct(
        private readonly string $localModel,
        private readonly string $localModelUrl,
        private readonly int $localContextWindow,
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
            $choices[$preset->provider->label()][$preset->adminChoiceLabel()] = $preset->id;
        }

        return $choices;
    }

    /**
     * Grouped ChoiceType values for valid model + reasoning combinations.
     *
     * @return array<string, array<string, string>>
     */
    public function groupedCombinationChoices(): array
    {
        $choices = [];
        foreach ($this->all() as $preset) {
            foreach ($preset->reasoningLevels as $level) {
                $label = $preset->label.' · '.$level->label();
                if ($preset->leavesHost()) {
                    $label .= ' · OM content leaves the host';
                }
                if (null !== $preset->apiKeyEnv) {
                    $label .= ' · requires '.$preset->apiKeyEnv;
                }
                $choices[$preset->provider->label()][$label] = $this->combinationId($preset->id, $level);
            }
        }

        return $choices;
    }

    public function combinationId(string $presetId, MemoryReasoningLevel $level): string
    {
        return $presetId.'|'.$level->value;
    }

    /**
     * @return array{presetId: string, reasoningLevel: MemoryReasoningLevel}
     */
    public function parseCombinationId(string $combinationId): array
    {
        $parts = explode('|', $combinationId, 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \InvalidArgumentException(\sprintf('Invalid OM model combination "%s".', $combinationId));
        }
        $level = MemoryReasoningLevel::tryFrom($parts[1])
            ?? throw new \InvalidArgumentException(\sprintf('Unknown OM reasoning level "%s".', $parts[1]));
        $preset = $this->require($parts[0]);
        if (!$preset->supports($level)) {
            throw new \InvalidArgumentException(\sprintf('Reasoning level "%s" is not available for "%s".', $level->value, $preset->id));
        }

        return ['presetId' => $preset->id, 'reasoningLevel' => $level];
    }

    /**
     * @return list<MemoryReasoningLevel>
     */
    public function reasoningLevelsFor(string $presetId): array
    {
        return $this->require($presetId)->reasoningLevels;
    }

    /**
     * @return array<string, MemoryModelPreset>
     */
    private function build(): array
    {
        $localModelId = $this->providerModelName($this->localModel);
        $localThinkingFormat = $this->resolveLocalThinkingFormat();
        $localSupportsOff = MemoryThinkingFormat::LlamaCpp === $localThinkingFormat;
        $local = new MemoryModelPreset(
            id: self::LOCAL_PRESET_ID,
            provider: MemoryModelProvider::LlamaCpp,
            modelId: $localModelId,
            label: 'Local llama.cpp ('.$localModelId.')',
            bridge: MemoryModelBridge::Generic,
            thinkingFormat: $localThinkingFormat,
            defaultContextWindow: $this->localContextWindow,
            maxContextWindow: $this->localContextWindow,
            reasoningLevels: $localSupportsOff ? [MemoryReasoningLevel::Default, MemoryReasoningLevel::Off] : [MemoryReasoningLevel::Default],
            defaultReasoningLevel: MemoryReasoningLevel::Default,
            supportsTrueOff: $localSupportsOff,
            apiKeyEnv: null,
            baseUrl: $this->localModelUrl,
            completionsPath: str_ends_with(rtrim($this->localModelUrl, '/'), '/v1') ? '/chat/completions' : '/v1/chat/completions',
            configuredModelReference: $this->localModel,
        );

        $mistralLevels = [MemoryReasoningLevel::Low, MemoryReasoningLevel::High, MemoryReasoningLevel::Max];
        $mistralMediumLevels = [MemoryReasoningLevel::Low, MemoryReasoningLevel::Medium, MemoryReasoningLevel::High];
        $cloud = [
            new MemoryModelPreset(
                id: 'mistral/zai-glm-5-3-flash',
                provider: MemoryModelProvider::Mistral,
                modelId: 'zai-glm-5-3-flash',
                label: 'Z.ai GLM 5.3 Flash (via Mistral)',
                bridge: MemoryModelBridge::Mistral,
                thinkingFormat: MemoryThinkingFormat::Mistral,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $mistralLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_MISTRAL_API_KEY,
                baseUrl: self::MISTRAL_BASE_URL,
                completionsPath: self::MISTRAL_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'mistral/deepseek-v4-1-flash-1017',
                provider: MemoryModelProvider::Mistral,
                modelId: 'deepseek-v4-1-flash-1017',
                label: 'DeepSeek V4.1 Flash 1017 (via Mistral)',
                bridge: MemoryModelBridge::Mistral,
                thinkingFormat: MemoryThinkingFormat::Mistral,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: [MemoryReasoningLevel::Off, MemoryReasoningLevel::Low, MemoryReasoningLevel::High, MemoryReasoningLevel::Xhigh, MemoryReasoningLevel::Max],
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: true,
                apiKeyEnv: self::ENV_MISTRAL_API_KEY,
                baseUrl: self::MISTRAL_BASE_URL,
                completionsPath: self::MISTRAL_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'mistral/zai-glm-5-3',
                provider: MemoryModelProvider::Mistral,
                modelId: 'zai-glm-5-3',
                label: 'Z.ai GLM 5.3 (via Mistral)',
                bridge: MemoryModelBridge::Mistral,
                thinkingFormat: MemoryThinkingFormat::Mistral,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $mistralLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_MISTRAL_API_KEY,
                baseUrl: self::MISTRAL_BASE_URL,
                completionsPath: self::MISTRAL_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'mistral/mistral-large-4',
                provider: MemoryModelProvider::Mistral,
                modelId: 'mistral-large-4',
                label: 'Mistral Large 4',
                bridge: MemoryModelBridge::Mistral,
                thinkingFormat: MemoryThinkingFormat::Mistral,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $mistralMediumLevels,
                defaultReasoningLevel: MemoryReasoningLevel::Low,
                supportsTrueOff: false,
                apiKeyEnv: self::ENV_MISTRAL_API_KEY,
                baseUrl: self::MISTRAL_BASE_URL,
                completionsPath: self::MISTRAL_COMPLETIONS_PATH,
            ),
            new MemoryModelPreset(
                id: 'zai/glm-5.3-flash',
                provider: MemoryModelProvider::Zai,
                modelId: 'glm-5.3-flash',
                label: 'GLM 5.3 Flash',
                bridge: MemoryModelBridge::Generic,
                thinkingFormat: MemoryThinkingFormat::Zai,
                defaultContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                maxContextWindow: self::CLOUD_DEFAULT_CONTEXT_WINDOW,
                reasoningLevels: $mistralLevels,
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
