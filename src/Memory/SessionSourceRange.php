<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * One bounded Observer read: the canonical events found, the last source seq actually read
 * (null when the source holds nothing in range yet), the checkpoint just past it, and whether
 * the per-job byte budget stopped the read before the requested end. `carried` holds the
 * tool-call events a previous forced cut left open, re-read so their results can pair with them.
 */
final readonly class SessionSourceRange
{
    /**
     * @param list<SessionEventDTO> $events
     * @param list<SessionEventDTO> $carried
     */
    public function __construct(
        public array $events,
        public ?int $lastSeq,
        public ?SourceCheckpoint $end,
        public bool $truncated,
        public array $carried = [],
    ) {
    }
}
