<?php

declare(strict_types=1);

namespace App\Index\Source;

/** Source binding and canonical revision; no full-corpus counts or file witnesses. */
final readonly class SourceSnapshot
{
    public function __construct(
        public string $path,
        public string $identity,
        public int $revision,
    ) {
    }
}
