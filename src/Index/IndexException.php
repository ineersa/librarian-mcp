<?php

declare(strict_types=1);

namespace App\Index;

/**
 * Sanitized indexing/search failure. Never chain provider bodies or source text.
 */
final class IndexException extends \RuntimeException
{
    public static function sanitized(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, null === $previous ? null : new \RuntimeException($previous::class));
    }
}
