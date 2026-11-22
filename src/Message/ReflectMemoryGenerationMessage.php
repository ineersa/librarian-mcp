<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Port of the OM `observational_memory.reflect_generation` threshold job payload.
 *
 * Nullable selection fields keep legacy queue payloads on the env baseline.
 * They are ordinary properties (not constructor-promoted readonly) so PHP
 * unserialization of older envelopes leaves them null instead of undefined.
 */
final class ReflectMemoryGenerationMessage
{
    public ?string $presetId = null;
    public ?string $reasoningLevel = null;
    public ?int $contextWindow = null;

    public function __construct(
        public readonly int $sessionPk,
        public readonly string $generationId,
        public readonly string $observationSetHash,
        public readonly ?string $priorActiveGenerationId,
        public readonly string $reflectorModel,
        public readonly string $reflectorSchemaVersion,
        public readonly int $requiredStartSeq,
        public readonly int $requiredEndSeq,
        ?string $presetId = null,
        ?string $reasoningLevel = null,
        ?int $contextWindow = null,
    ) {
        $this->presetId = $presetId;
        $this->reasoningLevel = $reasoningLevel;
        $this->contextWindow = $contextWindow;
    }
}
