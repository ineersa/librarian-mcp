<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\OmSourceBlockBuilder;
use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Read-only hydration of canonical Hatfield session events.jsonl journals.
 *
 * Paths are derived only from a discovered sessions root plus a validated
 * session/run id. Never writes journals and never accepts absolute client paths.
 */
final class SessionEventsJournalReader implements SessionSourceReader
{
    private const string EVENTS_BASENAME = 'events.jsonl';
    private const string PENDING_APPEND_SUFFIX = '.append.pending.json';
    private const int MAX_MANIFEST_BYTES = 4096;

    /**
     * Exact recall evidence for requested seqs.
     *
     * Matches original OM OmQueryService::loadEventsForRefs: resolve the inclusive
     * journal range once, keep only wanted seqs, and do not apply Librarian-only
     * evidence/line/scan caps. Observer budgets stay on readRange().
     *
     * @param list<int> $seqs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    public function loadForSession(
        string $sessionsPath,
        string $sessionId,
        array $seqs,
        ?callable $checkpoint = null,
        ?SourceCheckpoint $from = null,
    ): array {
        if ([] === $seqs) {
            return [];
        }

        $pathOrError = $this->resolveJournalPath($sessionsPath, $sessionId);
        if (isset($pathOrError['ok']) && false === $pathOrError['ok']) {
            return $pathOrError;
        }
        /** @var string $journalPath */
        $journalPath = $pathOrError['path'];

        /** @var array<int, true> $wanted */
        $wanted = [];
        foreach ($seqs as $seq) {
            if ($seq >= 1) {
                $wanted[$seq] = true;
            }
        }
        if ([] === $wanted) {
            return [];
        }
        $minSeq = min(array_keys($wanted));
        $maxSeq = max(array_keys($wanted));

        $handle = @fopen($journalPath, 'rb');
        if (false === $handle) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::EventsUnreadable->value,
                'message' => 'Session event journal is unreadable.',
            ];
        }

        $events = [];
        try {
            try {
                $cut = $this->committedCut($journalPath, JsonlLines::size($handle));
                $from = JsonlLines::usable($handle, $from, $cut, $minSeq);
                $start = $from->offset ?? 0;
            } catch (\RuntimeException $e) {
                return [
                    'ok' => false,
                    'error' => MemoryErrorCode::EventsUnreadable->value,
                    'message' => $e->getMessage(),
                ];
            }

            foreach (JsonlLines::completeLines($handle, $start, $cut) as $read) {
                if (null !== $checkpoint) {
                    $interrupt = $checkpoint();
                    if (\is_array($interrupt)) {
                        return $interrupt;
                    }
                }

                $line = $read['line'];
                // Cheap pre-filter: skip lines of other runs without decoding large payloads.
                if (!str_contains($line, $sessionId)) {
                    continue;
                }

                try {
                    $event = $this->eventFromLine($line, $sessionId);
                } catch (\RuntimeException $e) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => $e->getMessage(),
                    ];
                }
                if (null === $event) {
                    continue;
                }
                if ($event->seq > $maxSeq) {
                    break;
                }
                if ($event->seq < $minSeq || !isset($wanted[$event->seq])) {
                    continue;
                }

                $events[$event->seq] = [
                    'run_id' => $event->runId,
                    'seq' => $event->seq,
                    'type' => $event->type,
                    'created_at' => $event->createdAt,
                    'payload' => $event->payload,
                ];

                if (\count($events) === \count($wanted)) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        ksort($events);

        return array_values($events);
    }

    /**
     * Read-only range read for the Observer (port of Hatfield SessionEventReader::readRange).
     *
     * Starts at a verified checkpoint when one precedes the range, reads at most the
     * per-job byte budget of in-range events, and never past Hatfield's committed cut.
     *
     * @throws \RuntimeException when the journal is missing, unreadable, or malformed in range
     */
    public function readRange(string $sessionsPath, string $sessionId, int $startSeq, int $endSeq, ?SourceCheckpoint $from = null): SessionSourceRange
    {
        if ($startSeq < 1 || $endSeq < $startSeq) {
            throw new \InvalidArgumentException('Journal range requires 1 <= start_seq <= end_seq.');
        }
        $pathOrError = $this->resolveJournalPath($sessionsPath, $sessionId);
        if (!isset($pathOrError['path'])) {
            throw new \RuntimeException($pathOrError['message']);
        }

        $handle = @fopen($pathOrError['path'], 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Session event journal is unreadable.');
        }

        try {
            $cut = $this->committedCut($pathOrError['path'], JsonlLines::size($handle));
            $from = JsonlLines::usable($handle, $from, $cut, $startSeq);
            $carried = BudgetedRangeCollector::readCarried(
                $handle,
                $cut,
                $from->openToolCalls ?? [],
                function (string $line) use ($sessionId): array {
                    $event = $this->eventFromLine($line, $sessionId);

                    return null === $event ? [] : [$event];
                },
            );
            $collector = new BudgetedRangeCollector($endSeq, $carried['refs']);
            foreach (JsonlLines::completeLines($handle, $from->offset ?? 0, $cut) as $read) {
                $line = $read['line'];
                // Cheap pre-filter: skip lines of other runs without decoding large payloads.
                if (!str_contains($line, $sessionId)) {
                    continue;
                }
                $event = $this->eventFromLine($line, $sessionId);
                if (null === $event) {
                    continue;
                }
                $seq = $event->seq;
                // Journals are append-only with ascending seq; stop past the wanted window.
                if ($seq > $endSeq) {
                    break;
                }
                if ($seq < $startSeq) {
                    continue;
                }
                // Seqs ascend through the journal, so events arrive already ordered.
                if (!$collector->add($seq, $read['start'], $read['end'], \strlen($line), [$event])) {
                    break;
                }
            }

            return $collector->result($handle, $carried['events']);
        } finally {
            fclose($handle);
        }
    }

    public function sessionIds(string $sourceRoot): array
    {
        clearstatcache(true, $sourceRoot);
        $root = realpath($sourceRoot);
        $entries = false === $root || !is_dir($root) ? false : scandir($root);
        $ids = [];
        foreach (false === $entries ? [] : $entries as $name) {
            if ('.' !== $name && '..' !== $name && isset($this->resolveJournalPath($root, $name)['path'])) {
                $ids[] = $name;
            }
        }
        sort($ids);

        return $ids;
    }

    public function rangeEndSeqs(string $sourceRoot, string $sessionId, int $coveredSeq, int $rangeBytes): array
    {
        if ($coveredSeq < 0 || $rangeBytes < 1) {
            throw new \InvalidArgumentException('Journal ranges require covered_seq >= 0 and range_bytes >= 1.');
        }
        $pathOrError = $this->resolveJournalPath($sourceRoot, $sessionId);
        if (!isset($pathOrError['path'])) {
            throw new \RuntimeException($pathOrError['message']);
        }
        $handle = @fopen($pathOrError['path'], 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Session event journal is unreadable.');
        }

        $ends = [];
        $bytes = 0;
        $lastSeq = 0;
        $openCalls = new OpenToolCalls();
        try {
            // Only committed bytes: an append Hatfield has not finalized is not readable yet.
            $cut = $this->committedCut($pathOrError['path'], JsonlLines::size($handle));
            foreach (JsonlLines::completeLines($handle, 0, $cut) as $read) {
                if (!str_contains($read['line'], $sessionId)) {
                    continue;
                }
                $event = $this->eventFromLine($read['line'], $sessionId);
                if (null === $event || $event->seq <= $coveredSeq) {
                    continue;
                }
                $bytes += $read['end'] - $read['start'];
                $lastSeq = $event->seq;
                if (\in_array($event->type, OmSourceBlockBuilder::RENDERED_EVENT_TYPES, true)) {
                    $openCalls->track($event, $read['start'], $read['end']);
                }
                // A requested end flushes open calls unmatched, so only cut where none is waiting.
                if ($bytes >= $rangeBytes && $openCalls->isEmpty()) {
                    $ends[] = $lastSeq;
                    $bytes = 0;
                }
            }
        } finally {
            fclose($handle);
        }
        if ($bytes > 0) {
            $ends[] = $lastSeq;
        }

        return $ends;
    }

    /**
     * The canonical event on one journal line of this run, or null for other runs and blank lines.
     *
     * @throws \RuntimeException on corrupt JSON or an unexpected event shape
     */
    private function eventFromLine(string $line, string $sessionId): ?SessionEventDTO
    {
        $line = trim($line);
        if ('' === $line) {
            return null;
        }
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Session event journal contains corrupt JSON.', previous: $e);
        }
        if (!\is_array($decoded) || ($decoded['run_id'] ?? null) !== $sessionId) {
            return null;
        }
        $seq = $decoded['seq'] ?? null;
        if (!\is_int($seq)) {
            return null;
        }
        $type = $decoded['type'] ?? null;
        $payload = $decoded['payload'] ?? null;
        $createdAt = $decoded['ts'] ?? ($decoded['created_at'] ?? '');
        if (!\is_string($type) || '' === $type || !\is_array($payload) || !\is_string($createdAt)) {
            throw new \RuntimeException('Session event journal contains an unexpected event shape.');
        }
        $turnNo = $decoded['turn_no'] ?? 0;

        return new SessionEventDTO(
            runId: $sessionId,
            seq: $seq,
            turnNo: \is_int($turnNo) ? $turnNo : 0,
            type: $type,
            payload: $payload,
            createdAt: $createdAt,
        );
    }

    /**
     * Readable end of the journal. While Hatfield has an append pending, bytes past the
     * manifest offset are not committed yet (Hatfield JsonlAppendJournal::readableOffset()).
     * $size must be sampled before the manifest is read.
     */
    private function committedCut(string $journalPath, int $size): int
    {
        $manifestPath = $journalPath.self::PENDING_APPEND_SUFFIX;
        clearstatcache(true, $manifestPath);
        if (!is_file($manifestPath)) {
            return $size;
        }
        $bytes = @file_get_contents($manifestPath, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
        if (false === $bytes) {
            // Finalized and removed between the check and the read: the whole file is committed.
            return $size;
        }
        try {
            $manifest = json_decode($bytes, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Session event journal has an unreadable pending-append manifest.', previous: $e);
        }
        $offset = \is_array($manifest) ? ($manifest['offset'] ?? null) : null;
        if (!\is_int($offset) || $offset < 0) {
            throw new \RuntimeException('Session event journal pending-append manifest is inconsistent.');
        }

        // Hatfield appends without Librarian sharing its lock: a manifest past the size sampled
        // earlier belongs to a newer append, so every byte up to that size is already committed.
        return min($offset, $size);
    }

    /**
     * @return array{path: string}|array{ok: false, error: string, message: string}
     */
    private function resolveJournalPath(string $sessionsPath, string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if (
            '' === $sessionId
            || str_contains($sessionId, '/')
            || str_contains($sessionId, '\\')
            || str_contains($sessionId, "\0")
            || '.' === $sessionId
            || '..' === $sessionId
        ) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::EventsUnreadable->value,
                'message' => 'The matched memory has an invalid source session identifier.',
            ];
        }

        clearstatcache(true, $sessionsPath);
        $sessionsRoot = realpath($sessionsPath);
        if (false === $sessionsRoot || !is_dir($sessionsRoot)) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::SessionsUnavailable->value,
                'message' => 'Configured session journal root is missing or unreadable.',
            ];
        }

        $sessionDirectory = $sessionsRoot.\DIRECTORY_SEPARATOR.$sessionId;
        clearstatcache(true, $sessionDirectory);
        // Root is readable but the session directory is gone (not a broken link): the
        // client deleted the session, and retrying cannot bring its events back. Its memory
        // remains until the client's delete call or a cleanup removes it.
        if (!file_exists($sessionDirectory) && !is_link($sessionDirectory)) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::SessionDeleted->value,
                'message' => 'The matched memory belongs to a session that has been deleted; its source events are no longer available.',
            ];
        }
        $resolvedSessionDirectory = realpath($sessionDirectory);
        if (
            false === $resolvedSessionDirectory
            || !is_dir($resolvedSessionDirectory)
            || !$this->isPathInsideRoot($sessionsRoot, $resolvedSessionDirectory)
        ) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::EventsUnreadable->value,
                'message' => 'Session event journal is missing or outside the configured sessions root.',
            ];
        }

        $journalPath = $resolvedSessionDirectory.\DIRECTORY_SEPARATOR.self::EVENTS_BASENAME;
        clearstatcache(true, $journalPath);
        // Reject symlinked journals so resolution cannot escape the sessions root.
        if (is_link($journalPath) || !is_file($journalPath) || !is_readable($journalPath)) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::EventsUnreadable->value,
                'message' => 'Session event journal is missing or unreadable.',
            ];
        }

        $resolvedJournal = realpath($journalPath);
        if (
            false === $resolvedJournal
            || !is_file($resolvedJournal)
            || !$this->isPathInsideRoot($sessionsRoot, \dirname($resolvedJournal))
            || self::EVENTS_BASENAME !== basename($resolvedJournal)
        ) {
            return [
                'ok' => false,
                'error' => MemoryErrorCode::EventsUnreadable->value,
                'message' => 'Session event journal is missing or outside the configured sessions root.',
            ];
        }

        return ['path' => $resolvedJournal];
    }

    private function isPathInsideRoot(string $root, string $path): bool
    {
        $root = rtrim($root, \DIRECTORY_SEPARATOR);
        $path = rtrim($path, \DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root.\DIRECTORY_SEPARATOR);
    }
}
