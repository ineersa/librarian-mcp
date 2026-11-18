<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Byte offset just past the source line holding `seq`, fingerprinted by the bytes before it.
 */
final readonly class SourceCheckpoint
{
    public function __construct(
        public int $seq,
        public int $offset,
        public string $boundaryHash,
    ) {
    }
}
