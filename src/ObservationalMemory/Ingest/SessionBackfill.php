<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use App\Entity\Project;
use App\Memory\MemorySourceReaders;

/**
 * Queues observe signals for a project's existing Claude Code transcripts and Hatfield journals,
 * one ordinary signal per consecutive seq range, so the OM consumer processes them like a live
 * session. Only queues: no model calls, no waiting. Ranges already covered are skipped.
 */
final readonly class SessionBackfill
{
    /** Raw source bytes per observe signal; tune after the first production backfill. */
    public const int RANGE_BYTES = 1_048_576;

    public function __construct(
        private MemorySourceReaders $readers,
        private MemoryIngestService $ingest,
    ) {
    }

    public function isAvailable(Project $project): bool
    {
        foreach (MemorySourceKind::cases() as $sourceKind) {
            if (null !== $this->sourceRoot($project, $sourceKind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts per source kind whose sources are available.
     *
     * @return list<SessionBackfillResult>
     */
    public function queue(Project $project): array
    {
        $results = [];
        foreach (MemorySourceKind::cases() as $sourceKind) {
            $sourceRoot = $this->sourceRoot($project, $sourceKind);
            if (null !== $sourceRoot) {
                $results[] = $this->queueKind($project, $sourceKind, $sourceRoot);
            }
        }

        return $results;
    }

    private function queueKind(Project $project, MemorySourceKind $sourceKind, string $sourceRoot): SessionBackfillResult
    {
        $reader = $this->readers->reader($sourceKind);
        $queuedSessions = 0;
        $skippedSessions = 0;
        $signals = 0;
        foreach ($reader->sessionIds($sourceRoot) as $sessionId) {
            if (!MemoryObserveRequest::isSessionIdentifier($sessionId)) {
                ++$skippedSessions;
                continue;
            }
            $covered = $this->ingest->coveredEndSeq($project, $sourceKind, $sessionId) ?? 0;
            $endSeqs = $reader->rangeEndSeqs($sourceRoot, $sessionId, $covered, self::RANGE_BYTES);
            if ([] === $endSeqs) {
                ++$skippedSessions;
                continue;
            }
            foreach ($endSeqs as $endSeq) {
                $this->ingest->observe($project, MemoryObserveRequest::forRange($sourceKind, $sessionId, $endSeq));
                ++$signals;
            }
            ++$queuedSessions;
        }

        return new SessionBackfillResult($sourceKind, $queuedSessions, $skippedSessions, $signals);
    }

    /** The root the observe handler resolves: root_canonical for Claude, the discovered sessions path for Hatfield. */
    private function sourceRoot(Project $project, MemorySourceKind $sourceKind): ?string
    {
        if ('' === $project->rootCanonical) {
            return null;
        }

        return $this->readers->sourceRoot($sourceKind, $project->rootCanonical, $project->sessionsPath);
    }
}
