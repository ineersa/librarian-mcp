<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Read-only access to one client's session transcripts, addressed by a discovered
 * source root plus a validated session id. Sequence numbers are the client's own
 * stable positions (Hatfield event seq, Claude transcript line number).
 *
 * Both reads start at an optional checkpoint instead of the top of the file; readers ignore
 * a checkpoint that no longer matches the file's bytes and fall back to scanning from the top.
 */
interface SessionSourceReader
{
    /**
     * Canonical events in [startSeq, endSeq] for the Observer, stopping early once the
     * per-job byte budget is spent (the result is then marked truncated).
     *
     * @throws \RuntimeException when the transcript is missing, unreadable, or malformed in range
     */
    public function readRange(string $sourceRoot, string $sessionId, int $startSeq, int $endSeq, ?SourceCheckpoint $from = null): SessionSourceRange;

    /**
     * Bounded recall evidence for the given seqs.
     *
     * @param list<int> $seqs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    public function loadForSession(string $sourceRoot, string $sessionId, array $seqs, ?callable $checkpoint = null, ?SourceCheckpoint $from = null): array;
}
