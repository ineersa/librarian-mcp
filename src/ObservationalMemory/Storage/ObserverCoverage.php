<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Storage;

final class ObserverCoverage
{
    private const int GAP_LIMIT = 10;

    public private(set) int $contiguousEnd = 0;
    public private(set) int $latestEnd = 0;
    public private(set) int $gapCount = 0;
    /** @var list<array{start: int, end: int}> */
    public private(set) array $gaps = [];

    /** Merge complete chunks in ascending start order. */
    public function includeChunk(int $start, int $end): void
    {
        if ($start > $this->latestEnd + 1) {
            ++$this->gapCount;
            if (\count($this->gaps) < self::GAP_LIMIT) {
                $this->gaps[] = ['start' => $this->latestEnd + 1, 'end' => $start - 1];
            }
        }
        $this->latestEnd = max($end, $this->latestEnd);
        if (0 === $this->gapCount) {
            $this->contiguousEnd = $this->latestEnd;
        }
    }

    public function covers(int $target): bool
    {
        return 0 < $target && $target <= $this->contiguousEnd;
    }
}
