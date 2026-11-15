<?php

declare(strict_types=1);

namespace App\Index;

/**
 * Sanitized indexing/search failure. Never chain provider bodies or source text.
 */
final class IndexException extends \RuntimeException
{
    public const int QUERY_EMBEDDING_FAILED = 1;

    public const int QUERY_RETRIEVAL_FAILED = 2;

    public const int QUERY_TIMEOUT = 3;

    public static function sanitized(string $message, ?\Throwable $previous = null, int $code = 0): self
    {
        return new self($message, $code, null === $previous ? null : new \RuntimeException($previous::class));
    }
}
