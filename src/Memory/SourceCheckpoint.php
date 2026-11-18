<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Byte offset just past the source line holding `seq`, fingerprinted by the bytes before it.
 * `openToolCalls` are calls a forced budget cut left waiting for results; only the job that
 * continues from this exact checkpoint re-reads them.
 */
final readonly class SourceCheckpoint
{
    /**
     * @param list<OpenToolCallRef> $openToolCalls
     */
    public function __construct(
        public int $seq,
        public int $offset,
        public string $boundaryHash,
        public array $openToolCalls = [],
    ) {
    }
}
