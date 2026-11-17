<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

/**
 * Port of Hatfield ExtensionApi AgentToolDTO.
 */
final readonly class MemoryAgentTool
{
    /**
     * @param array<string, mixed> $parametersJsonSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parametersJsonSchema,
        public MemoryToolHandlerInterface $handler,
    ) {
    }
}
