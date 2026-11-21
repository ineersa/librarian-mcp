<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Forward reading of append-only JSONL sources, after Hatfield's
 * JsonlRunEventLog::locatedLines(): seek to a byte offset, read in small fgets chunks so
 * memory never scales with the whole file, and stop at the readable cut.
 */
final class JsonlLines
{
    /** Observer rendered-event bytes per job; a longer backlog continues in a follow-up job. */
    public const int RANGE_BUDGET_BYTES = 8_388_608;

    /** fgets(length) allocates the requested buffer even for short lines; keep it small. */
    private const int CHUNK_BYTES = 8193;
    private const int BOUNDARY_BYTES = 4096;
    private const string HASH_ALGORITHM = 'sha256';

    /**
     * Complete newline-terminated lines from $start up to $cut. An unterminated tail is
     * still being written and is never yielded. Every complete line is returned in full.
     *
     * Exact Hatfield/Claude recall and Observer range reads must not silently omit records.
     *
     * @param resource $handle
     *
     * @return \Generator<int, array{start: int, end: int, line: string}>
     */
    public static function completeLines($handle, int $start, int $cut): \Generator
    {
        if ($start < 0 || $start > $cut || 0 !== fseek($handle, $start)) {
            throw new \RuntimeException('Cannot seek session source to its read position.');
        }
        $position = $start;
        $lineStart = $start;
        $line = '';
        while ($position < $cut && false !== ($chunk = fgets($handle, min(self::CHUNK_BYTES, $cut - $position + 1)))) {
            $position += \strlen($chunk);
            $line .= $chunk;
            if (str_ends_with($chunk, "\n")) {
                yield ['start' => $lineStart, 'end' => $position, 'line' => $line];
                $lineStart = $position;
                $line = '';
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
        if ($offset < 0 || $length < 1 || $offset + $length > $cut) {
            return null;
        }
        foreach (self::completeLines($handle, $offset, $offset + $length) as $read) {
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
