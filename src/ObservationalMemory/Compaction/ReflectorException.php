<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Compaction;

use App\Entity\MemoryGenerationFailureCode;

/**
 * Durable Reflector failure with stable failure_code for storage/TUI.
 */
final class ReflectorException extends \RuntimeException
{
    public function __construct(
        public readonly MemoryGenerationFailureCode $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
