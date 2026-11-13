<?php

declare(strict_types=1);

namespace App\Index\Source;

/**
 * Bounded filesystem/source identity snapshot for one OM database.
 *
 * Stable identity is path+device+inode only. Mutable witness covers size/mtime/
 * revision for cheap same-revision restore signals. An in-place restore that
 * preserves path/dev/ino/size/mtime/revision remains undetectable.
 */
final readonly class SourceSnapshot
{
    public function __construct(
        public string $path,
        public string $identity,
        public string $witness,
        public ?int $revision,
        public int $observationCount,
        public int $reflectionCount,
        public int $changeHintCount,
    ) {
    }

    public function isPopulated(): bool
    {
        return $this->observationCount > 0 || $this->reflectionCount > 0;
    }
}
