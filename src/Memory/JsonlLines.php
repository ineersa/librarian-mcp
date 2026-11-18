<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Bounded forward reading of append-only JSONL sources, after Hatfield's
 * JsonlRunEventLog::locatedLines(): seek to a byte offset, read in small fgets chunks so
 * memory never scales with a line or the file, and stop at the readable cut.
 */
final class JsonlLines
{
    /** Observer bytes per job; a longer backlog continues in a follow-up job. */
    public const int RANGE_BUDGET_BYTES = 8_388_608;
    /** Hatfield's own per-record staging limit; longer lines are skipped by the Observer. */
    public const int MAX_RECORD_BYTES = 16_777_216;
    /** Distinct source lines of open tool calls one forced cut carries into its continuation. */
    public const int MAX_CARRIED_LINES = 16;

    /** fgets(length) allocates the requested buffer even for short lines; keep it small. */
    private const int CHUNK_BYTES = 8193;
    private const int BOUNDARY_BYTES = 4096;
    private const string HASH_ALGORITHM = 'sha256';

    /**
     * Complete newline-terminated lines from $start up to $cut. An unterminated tail is
     * still being written and is never yielded. Lines longer than $maxLineBytes are
     * consumed without being kept and yield null content.
     *
     * @param resource $handle
     *
     * @return \Generator<int, array{start: int, end: int, line: ?string}>
     */
    public static function lines($handle, int $start, int $cut, int $maxLineBytes): \Generator
    {
        if ($start < 0 || $start > $cut || 0 !== fseek($handle, $start)) {
            throw new \RuntimeException('Cannot seek session source to its read position.');
        }
        $position = $start;
        $lineStart = $start;
        $line = '';
        $oversized = false;
        while ($position < $cut && false !== ($chunk = fgets($handle, min(self::CHUNK_BYTES, $cut - $position + 1)))) {
            $position += \strlen($chunk);
            if (!$oversized) {
                $line .= $chunk;
                if (\strlen($line) > $maxLineBytes) {
                    $oversized = true;
                    $line = '';
                }
            }
            if (str_ends_with($chunk, "\n")) {
                yield ['start' => $lineStart, 'end' => $position, 'line' => $oversized ? null : $line];
                $lineStart = $position;
                $line = '';
                $oversized = false;
            }
        }
    }

    /**
     * Exactly one complete line at a known location before $cut, or null when it is not there.
     *
     * @param resource $handle
     */
    public static function lineAt($handle, int $offset, int $length, int $cut): ?string
    {
        if ($offset < 0 || $length < 1 || $length > self::MAX_RECORD_BYTES || $offset + $length > $cut) {
            return null;
        }
        foreach (self::lines($handle, $offset, $offset + $length, $length) as $read) {
            return $read['end'] === $offset + $length ? $read['line'] : null;
        }

        return null;
    }

    /**
     * The checkpoint when it still fingerprints this file and precedes $beforeSeq; null otherwise.
     *
     * @param resource $handle
     */
    public static function usable($handle, ?SourceCheckpoint $checkpoint, int $cut, int $beforeSeq): ?SourceCheckpoint
    {
        if (null === $checkpoint || $checkpoint->seq >= $beforeSeq || $checkpoint->offset < 1 || $checkpoint->offset > $cut) {
            return null;
        }

        return self::boundaryHash($handle, $checkpoint->offset) === $checkpoint->boundaryHash ? $checkpoint : null;
    }

    /**
     * @param resource              $handle
     * @param list<OpenToolCallRef> $openToolCalls
     */
    public static function checkpoint($handle, int $seq, int $offset, array $openToolCalls = []): ?SourceCheckpoint
    {
        $hash = self::boundaryHash($handle, $offset);

        return null === $hash ? null : new SourceCheckpoint($seq, $offset, $hash, $openToolCalls);
    }

    /**
     * @param resource $handle
     */
    public static function size($handle): int
    {
        $stat = fstat($handle);
        if (false === $stat) {
            throw new \RuntimeException('Cannot inspect session source size.');
        }

        return $stat['size'];
    }

    /**
     * Hash of the bytes just before a line boundary; null when $offset is not one.
     *
     * @param resource $handle
     */
    private static function boundaryHash($handle, int $offset): ?string
    {
        $length = min(self::BOUNDARY_BYTES, $offset);
        if ($length < 1 || 0 !== fseek($handle, $offset - $length)) {
            return null;
        }
        $bytes = fread($handle, $length);
        if (false === $bytes || \strlen($bytes) !== $length || !str_ends_with($bytes, "\n")) {
            return null;
        }

        return hash(self::HASH_ALGORITHM, $bytes);
    }
}
