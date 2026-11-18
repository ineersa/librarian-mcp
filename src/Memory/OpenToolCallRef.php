<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * A tool call still waiting for its result when a job's read budget forced a cut, located
 * by the source line that holds it so the continuation job can re-read it.
 */
final readonly class OpenToolCallRef
{
    public function __construct(
        public string $id,
        public int $seq,
        public int $offset,
        public int $length,
    ) {
    }
}
