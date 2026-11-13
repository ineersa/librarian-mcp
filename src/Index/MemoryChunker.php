<?php

declare(strict_types=1);

namespace App\Index;

/**
 * UTF-8 byte chunker matching Hatfield observational-memory MemoryChunker.
 */
final readonly class MemoryChunker
{
    public const int CHUNK_BYTES = 1200;
    public const int OVERLAP_BYTES = 192;
    public const int CHUNK_LINES = 80;

    /**
     * @return list<string>
     */
    public function split(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Memory text must be valid UTF-8.');
        }

        $chunks = [];
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            $chunk = mb_strcut($text, $offset, self::CHUNK_BYTES, 'UTF-8');
            $lines = explode("\n", $chunk);
            if (\count($lines) > self::CHUNK_LINES) {
                $chunk = implode("\n", \array_slice($lines, 0, self::CHUNK_LINES))."\n";
            }
            $chunks[] = $chunk;
            $end = $offset + \strlen($chunk);
            if ($end >= $length) {
                break;
            }

            // Advance at least one full codepoint even when a line limit leaves a
            // chunk shorter than the requested overlap. Never split UTF-8 bytes.
            $next = max($offset + 1, $end - self::OVERLAP_BYTES);
            while ($next < $end && (\ord($text[$next]) & 0xC0) === 0x80) {
                ++$next;
            }
            $offset = $next;
        }

        return $chunks;
    }
}
