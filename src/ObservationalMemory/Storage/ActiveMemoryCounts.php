<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

/**
 * Project-wide active observational-memory counts using OM selection semantics.
 */
final readonly class ActiveMemoryCounts
{
    public function __construct(
        public int $observations,
        public int $reflections,
    ) {
    }
}
