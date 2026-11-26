<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Memory\ClaudeTranscriptReader;

/**
 * Queues observe signals for a project's existing Claude Code transcripts, one ordinary
 * signal per consecutive seq range, so the OM consumer processes them like a live session.
 * Only queues: no model calls, no waiting. Ranges already covered are skipped.
 */
final readonly class ClaudeSessionBackfill
{
    /** Raw transcript bytes per observe signal; tune after the first production backfill. */
    public const int RANGE_BYTES = 1_048_576;

    public function __construct(
        private ClaudeTranscriptReader $transcripts,
        private MemoryIngestService $ingest,
    ) {
    }

    public function isAvailable(Project $project): bool
    {
        return null !== $this->transcriptDirectory($project);
    }

    public function queue(Project $project): ClaudeSessionBackfillResult
    {
        $directory = $this->transcriptDirectory($project)
            ?? throw new \RuntimeException('Claude transcripts for this project are not available.');

        $queuedSessions = 0;
        $skippedSessions = 0;
        $signals = 0;
        foreach ($this->transcripts->sessionIds($directory) as $sessionId) {
            $covered = $this->ingest->coveredEndSeq($project, MemorySourceKind::ClaudeCode, $sessionId) ?? 0;
            $endSeqs = $this->transcripts->rangeEndSeqs($directory, $sessionId, $covered, self::RANGE_BYTES);
            if ([] === $endSeqs) {
                ++$skippedSessions;
                continue;
            }
            foreach ($endSeqs as $endSeq) {
                $this->ingest->observe($project, MemoryObserveRequest::forRange(MemorySourceKind::ClaudeCode, $sessionId, $endSeq));
                ++$signals;
            }
            ++$queuedSessions;
        }

        return new ClaudeSessionBackfillResult($queuedSessions, $skippedSessions, $signals);
    }

    private function transcriptDirectory(Project $project): ?string
    {
        return $this->transcripts->transcriptDirectory('' !== $project->rootCanonical ? $project->rootCanonical : $project->rootPath);
    }
}
