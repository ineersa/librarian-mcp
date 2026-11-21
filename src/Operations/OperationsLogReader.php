<?php

declare(strict_types=1);

namespace App\Operations;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type RetryInfo array{transport: string, job: string, attempt: int, nextAt: int}
 * @phpstan-type Entry array{at: int, source: string, level: string, channel: string, summary: string, retry: RetryInfo|null}
 */
final readonly class OperationsLogReader
{
    public const int RETAINED_FILES = 3;
    private const int MAX_BYTES_PER_FILE = 65536;
    private const int MAX_ENTRIES = 50;

    public function __construct(
        #[Autowire(param: 'app.operations_log_dir')]
        private string $directory,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Entry> */
    public function recent(?LogSource $source = null, bool $waitingForRetry = false): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entries = [];
        for ($day = 0; $day < self::RETAINED_FILES; ++$day) {
            $date = $now->modify(\sprintf('-%d days', $day))->format('Y-m-d');
            foreach (null === $source ? LogSource::cases() : [$source] as $logSource) {
                $path = \sprintf('%s/%s-%s.log', $this->directory, $logSource->value, $date);
                foreach ($this->tail($path) as $line) {
                    $entry = $this->decode($line, $logSource);
                    if (null === $entry || ($waitingForRetry && ($entry['retry']['nextAt'] ?? 0) <= $now->getTimestamp())) {
                        continue;
                    }
                    $entries[] = $entry;
                }
            }
            if (\count($entries) >= self::MAX_ENTRIES) {
                break;
            }
        }
        usort($entries, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return \array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /** @return list<string> */
    private function tail(string $path): array
    {
        if (is_link($path) || !is_file($path) || false === $stream = @fopen($path, 'rb')) {
            return [];
        }

        try {
            $stat = fstat($stream);
            $offset = max(0, (false === $stat ? 0 : $stat['size']) - self::MAX_BYTES_PER_FILE);
            if (0 !== fseek($stream, $offset)) {
                return [];
            }
            $chunk = stream_get_contents($stream, self::MAX_BYTES_PER_FILE);
            if (false === $chunk) {
                return [];
            }
            $lines = explode("\n", $chunk);
            if (0 < $offset) {
                array_shift($lines); // A tail read may begin in the middle of a record.
            }

            return $lines;
        } finally {
            fclose($stream);
        }
    }

    /** @return Entry|null */
    private function decode(string $line, LogSource $source): ?array
    {
        $row = json_decode($line, true, 8);
        if (!\is_array($row) || !\is_int($row['at'] ?? null) || !\is_string($row['level'] ?? null) || !\is_string($row['channel'] ?? null) || !\is_string($row['summary'] ?? null)) {
            return null;
        }

        return [
            'at' => $row['at'],
            'source' => $source->label(),
            'level' => $row['level'],
            'channel' => $row['channel'],
            'summary' => $row['summary'],
            'retry' => \is_string($row['transport'] ?? null) && \is_string($row['job'] ?? null) && \is_int($row['attempt'] ?? null) && \is_int($row['nextAt'] ?? null)
                ? ['transport' => $row['transport'], 'job' => $row['job'], 'attempt' => $row['attempt'], 'nextAt' => $row['nextAt']]
                : null,
        ];
    }
}
