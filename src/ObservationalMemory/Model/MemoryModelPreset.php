<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * One admin-selectable OM model + reasoning combination.
 */
final readonly class MemoryModelPreset
{
    /**
     * @param non-empty-list<MemoryReasoningLevel> $reasoningLevels
     */
    public function __construct(
        public string $id,
        public MemoryModelProvider $provider,
        public string $modelId,
        public string $label,
        public MemoryModelBridge $bridge,
        public MemoryThinkingFormat $thinkingFormat,
        public int $defaultContextWindow,
        public int $maxContextWindow,
        public array $reasoningLevels,
        public MemoryReasoningLevel $defaultReasoningLevel,
        public bool $supportsTrueOff,
        public ?string $apiKeyEnv = null,
        public ?string $baseUrl = null,
        public string $completionsPath = '/chat/completions',
        private ?string $configuredModelReference = null,
    ) {
        if ('' === trim($this->id) || '' === trim($this->modelId) || '' === trim($this->label)) {
            throw new \InvalidArgumentException('OM model preset identity fields are required.');
        }
        if ($this->defaultContextWindow < 1 || $this->maxContextWindow < $this->defaultContextWindow) {
            throw new \InvalidArgumentException('OM model preset context windows are invalid.');
        }
        if (!\in_array($this->defaultReasoningLevel, $this->reasoningLevels, true)) {
            throw new \InvalidArgumentException(\sprintf('Default reasoning level "%s" is not available for preset "%s".', $this->defaultReasoningLevel->value, $this->id));
        }
    }

    public function reference(): string
    {
        return $this->configuredModelReference ?? ($this->provider->value.'/'.$this->modelId);
    }

    public function leavesHost(): bool
    {
        return $this->provider->leavesHost();
    }

    public function supports(MemoryReasoningLevel $level): bool
    {
        return \in_array($level, $this->reasoningLevels, true);
    }

    public function adminChoiceLabel(): string
    {
        $parts = [$this->label, 'default '.$this->defaultReasoningLevel->label()];
        if ($this->leavesHost()) {
            $parts[] = 'OM content leaves the host';
        }
        if (null !== $this->apiKeyEnv) {
            $parts[] = 'requires '.$this->apiKeyEnv;
        }

        return implode(' · ', $parts);
    }
}
