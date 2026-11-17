<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Read-only hydration of canonical Hatfield session events.jsonl journals.
 *
 * Paths are derived only from a discovered sessions root plus a validated
 * session/run id. Never writes journals and never accepts absolute client paths.
 */
final class SessionEventsJournalReader
{
    private const string EVENTS_BASENAME = 'events.jsonl';
    private const int MAX_LINE_BYTES = 1_048_576;
    private const int MAX_SCANNED_BYTES = 67_108_864;
    private const int MAX_EVIDENCE_BYTES = 1_048_576;

    /**
     * @param list<int> $seqs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string, timed_out?: true}
     */
    public function loadForSession(
        string $sessionsPath,
        string $sessionId,
        array $seqs,
        ?callable $checkpoint = null,
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
        $scannedBytes = 0;
        $evidenceBytes = 0;
        try {
            while (true) {
                if (null !== $checkpoint) {
                    $interrupt = $checkpoint();
                    if (\is_array($interrupt)) {
                        return $interrupt;
                    }
                }

                $line = fgets($handle, self::MAX_LINE_BYTES + 1);
                if (false === $line) {
                    break;
                }

                $lineBytes = \strlen($line);
                $scannedBytes += $lineBytes;
                if ($scannedBytes > self::MAX_SCANNED_BYTES) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::ResourceLimitExceeded->value,
                        'message' => 'Session event journal scan exceeded the bounded byte limit.',
                    ];
                }

                // fgets($length) returns at most length-1 bytes; an unterminated
                // max-size read means the physical line continues past the cap.
                if (!str_ends_with($line, "\n") && self::MAX_LINE_BYTES === $lineBytes) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::ResourceLimitExceeded->value,
                        'message' => 'Session event journal line exceeded the bounded byte limit.',
                    ];
                }

                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains corrupt JSON.',
                    ];
                }
                if (!\is_array($decoded)) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains an unexpected event shape.',
                    ];
                }

                $runId = $decoded['run_id'] ?? null;
                $seq = $decoded['seq'] ?? null;
                $type = $decoded['type'] ?? null;
                $createdAt = $decoded['ts'] ?? ($decoded['created_at'] ?? null);
                $payload = $decoded['payload'] ?? null;

                if (!\is_string($runId) || $runId !== $sessionId) {
                    continue;
                }
                if (!\is_int($seq) && !(is_numeric($seq) && (string) (int) $seq === (string) $seq)) {
                    continue;
                }
                $seq = (int) $seq;
                // Journals are append-only with ascending seq; stop past the wanted window.
                if ($seq > $maxSeq) {
                    break;
                }
                if (!isset($wanted[$seq])) {
                    continue;
                }
                if (!\is_string($type) || '' === $type) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains an unexpected event shape.',
                    ];
                }
                if (!\is_string($createdAt) || '' === $createdAt) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains an unexpected event shape.',
                    ];
                }
                if (!\is_array($payload)) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains an unexpected event shape.',
                    ];
                }

                $event = [
                    'run_id' => $runId,
                    'seq' => $seq,
                    'type' => $type,
                    'created_at' => $createdAt,
                    'payload' => $payload,
                ];
                try {
                    $encoded = json_encode($event, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::EventsUnreadable->value,
                        'message' => 'Session event journal contains an unexpected event shape.',
                    ];
                }
                $evidenceBytes += \strlen($encoded);
                if ($evidenceBytes > self::MAX_EVIDENCE_BYTES) {
                    return [
                        'ok' => false,
                        'error' => MemoryErrorCode::ResourceLimitExceeded->value,
                        'message' => 'Session event evidence exceeded the bounded response byte limit.',
                    ];
                }

                $events[$seq] = $event;

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
     * Streams the whole journal without recall's scan cap because live journals can be
     * 50-100 MB; only events inside [startSeq, endSeq] are decoded and kept.
     *
     * @return list<SessionEventDTO>
     *
     * @throws \RuntimeException when the journal is missing, unreadable, or malformed in range
     */
    public function readRange(string $sessionsPath, string $sessionId, int $startSeq, int $endSeq): array
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

        $events = [];
        try {
            while (false !== ($line = fgets($handle))) {
                // Cheap pre-filter: skip lines of other runs without decoding large payloads.
                if (!str_contains($line, $sessionId)) {
                    continue;
                }
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException('Session event journal contains corrupt JSON.', previous: $e);
                }
                if (!\is_array($decoded) || ($decoded['run_id'] ?? null) !== $sessionId) {
                    continue;
                }
                $seq = $decoded['seq'] ?? null;
                if (!\is_int($seq)) {
                    continue;
                }
                // Journals are append-only with ascending seq; stop past the wanted window.
                if ($seq > $endSeq) {
                    break;
                }
                if ($seq < $startSeq) {
                    continue;
                }
                $type = $decoded['type'] ?? null;
                $payload = $decoded['payload'] ?? null;
                $createdAt = $decoded['ts'] ?? ($decoded['created_at'] ?? '');
                if (!\is_string($type) || '' === $type || !\is_array($payload) || !\is_string($createdAt)) {
                    throw new \RuntimeException('Session event journal contains an unexpected event shape.');
                }
                $turnNo = $decoded['turn_no'] ?? 0;
                $events[$seq] = new SessionEventDTO(
                    runId: $sessionId,
                    seq: $seq,
                    turnNo: \is_int($turnNo) ? $turnNo : 0,
                    type: $type,
                    payload: $payload,
                    createdAt: $createdAt,
                );
            }
        } finally {
            fclose($handle);
        }

        ksort($events);

        return array_values($events);
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
