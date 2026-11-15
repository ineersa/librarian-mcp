<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Read-only hydration of canonical Hatfield session events.jsonl journals.
 *
 * Paths are derived only from a discovered sessions root plus a validated
 * session/run id. Never writes journals and never accepts absolute client paths.
 */
final class SessionEventsJournalReader
{
    private const string EVENTS_BASENAME = 'events.jsonl';

    /**
     * @param list<int> $seqs
     *
     * @return list<array{run_id: string, seq: int, type: string, created_at: string, payload: array<string, mixed>}>|array{ok: false, error: string, message: string}|array{cancelled?: true, timed_out?: true, timeout_seconds?: int, message: string}
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
                'error' => 'events_unreadable',
                'message' => 'Session event journal is unreadable.',
            ];
        }

        $events = [];
        try {
            while (false !== ($line = fgets($handle))) {
                if (null !== $checkpoint) {
                    $interrupt = $checkpoint();
                    if (\is_array($interrupt)) {
                        return $interrupt;
                    }
                }

                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (!\is_array($decoded)) {
                    continue;
                }

                $runId = $decoded['run_id'] ?? null;
                $seq = $decoded['seq'] ?? null;
                $type = $decoded['type'] ?? null;
                $createdAt = $decoded['ts'] ?? ($decoded['created_at'] ?? null);
                $payload = $decoded['payload'] ?? null;

                if (!\is_string($runId) || $runId !== $sessionId) {
                    continue;
                }
                if (!is_numeric($seq)) {
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
                    continue;
                }
                if (!\is_string($createdAt) || '' === $createdAt) {
                    continue;
                }
                if (!\is_array($payload)) {
                    $payload = [];
                }

                $events[$seq] = [
                    'run_id' => $runId,
                    'seq' => $seq,
                    'type' => $type,
                    'created_at' => $createdAt,
                    'payload' => $payload,
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
     * @return array{path: string}|array{ok: false, error: string, message: string}
     */
    private function resolveJournalPath(string $sessionsPath, string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId) {
            return [
                'ok' => false,
                'error' => 'invalid_session_id',
                'message' => 'session_id must be a non-empty session/run id when provided.',
            ];
        }

        if (
            str_contains($sessionId, '/')
            || str_contains($sessionId, '\\')
            || str_contains($sessionId, "\0")
            || '.' === $sessionId
            || '..' === $sessionId
        ) {
            return [
                'ok' => false,
                'error' => 'invalid_session_id',
                'message' => 'session_id must be a non-empty session/run id when provided.',
            ];
        }

        clearstatcache(true, $sessionsPath);
        $sessionsRoot = realpath($sessionsPath);
        if (false === $sessionsRoot || !is_dir($sessionsRoot)) {
            return [
                'ok' => false,
                'error' => 'sessions_unavailable',
                'message' => 'Configured session journal root is missing or unreadable.',
            ];
        }

        $sessionDirectory = $sessionsRoot.\DIRECTORY_SEPARATOR.$sessionId;
        clearstatcache(true, $sessionDirectory);
        $resolvedSessionDirectory = realpath($sessionDirectory);
        if (
            false === $resolvedSessionDirectory
            || !is_dir($resolvedSessionDirectory)
            || !$this->isPathInsideRoot($sessionsRoot, $resolvedSessionDirectory)
        ) {
            return [
                'ok' => false,
                'error' => 'events_unreadable',
                'message' => 'Session event journal is missing or outside the configured sessions root.',
            ];
        }

        $journalPath = $resolvedSessionDirectory.\DIRECTORY_SEPARATOR.self::EVENTS_BASENAME;
        clearstatcache(true, $journalPath);
        // Reject symlinked journals so resolution cannot escape the sessions root.
        if (is_link($journalPath) || !is_file($journalPath) || !is_readable($journalPath)) {
            return [
                'ok' => false,
                'error' => 'events_unreadable',
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
                'error' => 'events_unreadable',
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
