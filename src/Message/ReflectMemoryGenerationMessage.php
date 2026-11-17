<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Port of the OM `observational_memory.reflect_generation` threshold job payload.
 */
final class ReflectMemoryGenerationMessage
{
    public function __construct(
        public readonly int $sessionPk,
        public readonly string $generationId,
        public readonly string $observationSetHash,
        public readonly ?string $priorActiveGenerationId,
        public readonly string $reflectorModel,
        public readonly string $reflectorSchemaVersion,
        public readonly int $requiredStartSeq,
        public readonly int $requiredEndSeq,
    ) {
    }
}
