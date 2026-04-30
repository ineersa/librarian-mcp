<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

final class ToolCallException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly string $hint,
    ) {
        parent::__construct($message);
    }
}
