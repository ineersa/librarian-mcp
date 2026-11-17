<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Agent;

/**
 * Port of Hatfield ExtensionToolHandlerInterface for in-process OM model tools.
 */
interface MemoryToolHandlerInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): mixed;
}
