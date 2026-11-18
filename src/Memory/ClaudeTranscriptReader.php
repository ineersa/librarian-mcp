<?php

declare(strict_types=1);

namespace App\Memory;

use App\ObservationalMemory\Observer\SessionEventDTO;

/**
 * Read-only Claude Code transcripts: `<projects>/<encoded project root>/<session uuid>.jsonl`.
 *
 * Transcripts are append-only JSONL, so the 1-based physical line number is the stable
 * sequence number. Only main-thread user/assistant records carry conversation content; they are
 * mapped onto the canonical Hatfield event shapes OmSourceBlockBuilder renders, so the Observer,
 * coverage and recall stay source-agnostic. Never writes transcripts and never accepts client paths.
 */
final readonly class ClaudeTranscriptReader implements SessionSourceReader
{
    /** Claude Code session ids are lowercase UUIDs; anything else is not a transcript name. */
    public const string SESSION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string TRANSCRIPT_EXTENSION = '.jsonl';

    /** Claude Code names a project's transcript directory by replacing each non-alphanumeric character of its root with '-'. */
    private const string ROOT_ENCODING_PATTERN = '/[^A-Za-z0-9]/';

    private const string RECORD_USER = 'user';
    private const string RECORD_ASSISTANT = 'assistant';

    private const string BLOCK_TEXT = 'text';
    private const string BLOCK_TOOL_USE = 'tool_use';
    private const string BLOCK_TOOL_RESULT = 'tool_result';

    /** Canonical Hatfield event types rendered by OmSourceBlockBuilder. */
    private const string EVENT_USER = 'agent_command_applied';
    private const string EVENT_ASSISTANT = 'llm_step_completed';
    private const string EVENT_TOOL_RESULT = 'tool_execution_end';

    private const int MAX_EVIDENCE_LINE_BYTES = 1_048_576;
    private const int MAX_EVIDENCE_BYTES = 1_048_576;

    public function __construct(
        private string $claudeProjectsPath,
    ) {
    }

    /**
     * The project's transcript directory, or null when Claude transcripts are not configured or absent.
     */
    public function transcriptDirectory(string $projectRoot): ?string
    {
        $base = rtrim(trim($this->claudeProjectsPath), \DIRECTORY_SEPARATOR);
        if ('' === $base || '' === $projectRoot) {
            return null;
        }
        $directory = $base.\DIRECTORY_SEPARATOR.preg_replace(self::ROOT_ENCODING_PATTERN, '-', $projectRoot);
        clearstatcache(true, $directory);

        return is_dir($directory) && is_readable($directory) ? $directory : null;
    }

    public function countTranscripts(string $transcriptDirectory): int
    {
        $files = glob($transcriptDirectory.\DIRECTORY_SEPARATOR.'*'.self::TRANSCRIPT_EXTENSION);

        return false === $files ? 0 : \count($files);
    }

    public function readRange(string $sourceRoot, string $sessionId, int $startSeq, int $endSeq, ?SourceCheckpoint $from = null): SessionSourceRange
    {
        if ($startSeq < 1 || $endSeq < $startSeq) {
            throw new \InvalidArgumentException('Transcript range requires 1 <= start_seq <= end_seq.');
        }
        $pathOrError = $this->resolveTranscriptPath($sourceRoot, $sessionId);
        if (!isset($pathOrError['path'])) {
            throw new \RuntimeException($pathOrError['message']);
        }
        $handle = @fopen($pathOrError['path'], 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Claude transcript is unreadable.');
        }

        $events = [];
        /** @var array<string, string> $toolNames */
        $toolNames = [];
        $last = null;
        $truncated = false;
        $budget = JsonlLines::RANGE_BUDGET_BYTES;
        try {
            $cut = JsonlLines::size($handle);
            $from = JsonlLines::usable($handle, $from, $cut, $startSeq);
            // Line numbers are positions, so a checkpoint also fixes the numbering base.
            $lineNo = $from->seq ?? 0;
            // Unterminated last lines are still being written and are never yielded.
            foreach (JsonlLines::lines($handle, $from->offset ?? 0, $cut, JsonlLines::MAX_RECORD_BYTES) as $read) {
                ++$lineNo;
                if ($lineNo > $endSeq) {
                    break;
                }
                if ($lineNo < $startSeq) {
                    continue;
                }
                $last = ['seq' => $lineNo, 'end' => $read['end']];
                if (null === $read['line']) {
                    continue;
                }
                $budget -= \strlen($read['line']);
                $record = $this->decode($read['line']);
                if (null !== $record) {
                    foreach ($this->eventsFor($record, $sessionId, $lineNo, $toolNames) as $event) {
                        $events[] = $event;
                    }
                }
                if ($budget <= 0 && $lineNo < $endSeq) {
                    $truncated = true;
                    break;
                }
            }

            return new SessionSourceRange(
                $events,
                $last['seq'] ?? null,
                null === $last ? null : JsonlLines::checkpoint($handle, $last['seq'], $last['end']),
                $truncated,
            );
        } finally {
            fclose($handle);
        }
    }

    public function loadForSession(string $sourceRoot, string $sessionId, array $seqs, ?callable $checkpoint = null, ?SourceCheckpoint $from = null): array
    {
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

        $pathOrError = $this->resolveTranscriptPath($sourceRoot, $sessionId);
        if (!isset($pathOrError['path'])) {
            return $pathOrError;
        }
        $handle = @fopen($pathOrError['path'], 'rb');
        if (false === $handle) {
            return $this->failure(MemoryErrorCode::EventsUnreadable, 'Claude transcript is unreadable.');
        }

        $events = [];
        $evidenceBytes = 0;
        try {
            $cut = JsonlLines::size($handle);
            $from = JsonlLines::usable($handle, $from, $cut, min(array_keys($wanted)));
            $lineNo = $from->seq ?? 0;
            foreach (JsonlLines::lines($handle, $from->offset ?? 0, $cut, self::MAX_EVIDENCE_LINE_BYTES) as $read) {
                if (null !== $checkpoint && \is_array($interrupt = $checkpoint())) {
                    return $interrupt;
                }
                ++$lineNo;
                if ($lineNo > $maxSeq) {
                    break;
                }
                if (!isset($wanted[$lineNo])) {
                    continue;
                }
                if (null === $read['line']) {
                    $event = ['run_id' => $sessionId, 'seq' => $lineNo, 'type' => '', 'created_at' => '', 'payload' => ['omitted' => 'Transcript line exceeds the bounded evidence size.']];
                } else {
                    try {
                        $record = $this->decode($read['line']);
                    } catch (\RuntimeException $e) {
                        return $this->failure(MemoryErrorCode::EventsUnreadable, $e->getMessage());
                    }
                    if (null === $record) {
                        continue;
                    }
                    $message = \is_array($record['message'] ?? null) ? $record['message'] : null;
                    $event = [
                        'run_id' => $sessionId,
                        'seq' => $lineNo,
                        'type' => \is_string($record['type'] ?? null) ? $record['type'] : '',
                        'created_at' => \is_string($record['timestamp'] ?? null) ? $record['timestamp'] : '',
                        'payload' => null === $message ? [] : ['message' => $this->evidenceMessage($message)],
                    ];
                }
                $evidenceBytes += \strlen(json_encode($event, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
                if ($evidenceBytes > self::MAX_EVIDENCE_BYTES) {
                    return $this->failure(MemoryErrorCode::ResourceLimitExceeded, 'Claude transcript evidence exceeded the bounded response byte limit.');
                }
                $events[] = $event;
            }
        } catch (\JsonException) {
            return $this->failure(MemoryErrorCode::EventsUnreadable, 'Claude transcript contains an unexpected record shape.');
        } catch (\RuntimeException $e) {
            return $this->failure(MemoryErrorCode::EventsUnreadable, $e->getMessage());
        } finally {
            fclose($handle);
        }

        return $events;
    }

    /**
     * Role plus text-bearing content only: images and other binary blocks become type markers.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function evidenceMessage(array $message): array
    {
        $content = $message['content'] ?? null;
        if (\is_array($content)) {
            $content = array_map(fn (mixed $block): mixed => \is_array($block) ? $this->evidenceBlock($block) : $block, $content);
        }

        return ['role' => $message['role'] ?? null, 'content' => $content];
    }

    /**
     * @param array<mixed> $block
     *
     * @return array<string, mixed>
     */
    private function evidenceBlock(array $block): array
    {
        $type = \is_string($block['type'] ?? null) ? $block['type'] : '';

        return match ($type) {
            self::BLOCK_TEXT => ['type' => $type, 'text' => $block['text'] ?? ''],
            self::BLOCK_TOOL_USE => ['type' => $type, 'id' => $block['id'] ?? null, 'name' => $block['name'] ?? null, 'input' => $block['input'] ?? null],
            self::BLOCK_TOOL_RESULT => [
                'type' => $type,
                'tool_use_id' => $block['tool_use_id'] ?? null,
                'is_error' => true === ($block['is_error'] ?? false),
                'content' => \is_array($block['content'] ?? null)
                    ? array_map(fn (mixed $part): mixed => \is_array($part) ? $this->evidenceBlock($part) : $part, $block['content'])
                    : ($block['content'] ?? ''),
            ],
            default => ['type' => $type],
        };
    }

    /**
     * @return array<string, mixed>|null null for blank lines
     */
    private function decode(string $line): ?array
    {
        $line = trim($line);
        if ('' === $line) {
            return null;
        }
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Claude transcript contains corrupt JSON.', previous: $e);
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>  $record
     * @param array<string, string> $toolNames tool_use id => name, filled while reading
     *
     * @return list<SessionEventDTO>
     */
    private function eventsFor(array $record, string $sessionId, int $seq, array &$toolNames): array
    {
        $type = $record['type'] ?? null;
        $message = $record['message'] ?? null;
        // Subagent turns, injected meta text and compaction summaries repeat or sit outside the main conversation.
        if (
            !\is_array($message)
            || true === ($record['isSidechain'] ?? false)
            || true === ($record['isMeta'] ?? false)
            || true === ($record['isCompactSummary'] ?? false)
        ) {
            return [];
        }
        $createdAt = \is_string($record['timestamp'] ?? null) ? $record['timestamp'] : '';
        $content = $message['content'] ?? null;
        $blocks = \is_string($content) ? [['type' => self::BLOCK_TEXT, 'text' => $content]] : (\is_array($content) ? $content : []);

        $texts = [];
        $toolCalls = [];
        $events = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockType = $block['type'] ?? null;
            if (self::BLOCK_TEXT === $blockType && \is_string($block['text'] ?? null) && '' !== trim($block['text'])) {
                $texts[] = $block['text'];
            } elseif (self::RECORD_ASSISTANT === $type && self::BLOCK_TOOL_USE === $blockType && \is_string($block['id'] ?? null)) {
                $name = \is_string($block['name'] ?? null) ? $block['name'] : 'tool';
                $toolNames[$block['id']] = $name;
                $toolCalls[] = ['id' => $block['id'], 'name' => $name, 'arguments' => $block['input'] ?? []];
            } elseif (self::RECORD_USER === $type && self::BLOCK_TOOL_RESULT === $blockType && \is_string($block['tool_use_id'] ?? null)) {
                $toolCallId = $block['tool_use_id'];
                $events[] = new SessionEventDTO($sessionId, $seq, 0, self::EVENT_TOOL_RESULT, [
                    'tool_result' => [
                        'tool_call_id' => $toolCallId,
                        'is_error' => true === ($block['is_error'] ?? false),
                        'result' => ['tool_name' => $toolNames[$toolCallId] ?? 'tool', 'content' => $block['content'] ?? ''],
                    ],
                ], $createdAt);
            }
        }

        if (self::RECORD_USER === $type && [] !== $texts) {
            array_unshift($events, new SessionEventDTO($sessionId, $seq, 0, self::EVENT_USER, ['text' => implode("\n", $texts)], $createdAt));
        } elseif (self::RECORD_ASSISTANT === $type && ([] !== $texts || [] !== $toolCalls)) {
            $events[] = new SessionEventDTO($sessionId, $seq, 0, self::EVENT_ASSISTANT, [
                'assistant_message' => ['role' => self::RECORD_ASSISTANT, 'content' => implode("\n", $texts), 'tool_calls' => $toolCalls],
            ], $createdAt);
        }

        return $events;
    }

    /**
     * @return array{path: string}|array{ok: false, error: string, message: string}
     */
    private function resolveTranscriptPath(string $transcriptDirectory, string $sessionId): array
    {
        if (1 !== preg_match(self::SESSION_ID_PATTERN, $sessionId)) {
            return $this->failure(MemoryErrorCode::EventsUnreadable, 'The matched memory has an invalid Claude session identifier.');
        }
        clearstatcache(true, $transcriptDirectory);
        $root = realpath($transcriptDirectory);
        if (false === $root || !is_dir($root)) {
            return $this->failure(MemoryErrorCode::SessionsUnavailable, 'Claude transcript directory is missing or unreadable.');
        }
        $path = $root.\DIRECTORY_SEPARATOR.$sessionId.self::TRANSCRIPT_EXTENSION;
        clearstatcache(true, $path);
        // Directory readable but transcript gone: deleted or expired by cleanupPeriodDays.
        if (!file_exists($path) && !is_link($path)) {
            return $this->failure(MemoryErrorCode::SessionDeleted, 'The matched memory belongs to a session that has been deleted; its transcript is no longer available.');
        }
        $resolved = realpath($path);
        if (false === $resolved || !is_file($resolved) || \dirname($resolved) !== $root) {
            return $this->failure(MemoryErrorCode::EventsUnreadable, 'Claude transcript is missing or outside the project transcript directory.');
        }

        return ['path' => $resolved];
    }

    /**
     * @return array{ok: false, error: string, message: string}
     */
    private function failure(MemoryErrorCode $code, string $message): array
    {
        return ['ok' => false, 'error' => $code->value, 'message' => $message];
    }
}
