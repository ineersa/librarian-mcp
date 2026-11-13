<?php

declare(strict_types=1);

namespace App\Index\Source;

/**
 * Bounded filesystem/source identity snapshot for one OM database.
 *
 * Identical-revision in-place restores without filesystem identity change remain
 * undetectable by revision alone; callers must not claim that case is solved.
 */
final readonly class SourceSnapshot
{
    public function __construct(
        public string $path,
        public string $identity,
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
