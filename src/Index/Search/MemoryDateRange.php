<?php

declare(strict_types=1);

namespace App\Index\Search;

use App\Index\IndexFields;

/**
 * OM-compatible memory date window for native hybrid candidate filtering.
 *
 * Accepted inputs match Hatfield OM: YYYY-MM-DD or YYYY-MM-DD HH:MM.
 * Blank optional values are treated as absent. Bounds are UTC.
 *
 * - after is inclusive at the start of the given day or minute
 * - before is exclusive at the start of the next day or next minute
 */
final readonly class MemoryDateRange
{
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const string MINUTE_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';

    private function __construct(
        public ?int $afterTimestamp,
        public ?int $beforeTimestamp,
    ) {
    }

    public static function fromInputs(?string $after, ?string $before): self
    {
        $afterBound = self::normalizeBound($after, lowerBound: true, field: 'after');
        $beforeBound = self::normalizeBound($before, lowerBound: false, field: 'before');

        // Exclusive upper bounds make an empty window when after >= beforeExclusive.
        if (null !== $afterBound && null !== $beforeBound && $afterBound >= $beforeBound) {
            throw new \InvalidArgumentException('after must be less than or equal to before.');
        }

        return new self($afterBound, $beforeBound);
    }

    public function isEmpty(): bool
    {
        return null === $this->afterTimestamp && null === $this->beforeTimestamp;
    }

    /**
     * Fixed parameterized metadata predicates for the official Postgres store.
     *
     * @return array{where?: string, params?: array<string, int>}
     */
    public function toQueryOptions(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $clauses = [];
        $params = [];
        $field = IndexFields::OCCURRED_AT;

        if (null !== $this->afterTimestamp) {
            $clauses[] = \sprintf("(metadata->>'%s')::bigint >= :memory_after_ts", $field);
            $params['memory_after_ts'] = $this->afterTimestamp;
        }
        if (null !== $this->beforeTimestamp) {
            $clauses[] = \sprintf("(metadata->>'%s')::bigint < :memory_before_ts", $field);
            $params['memory_before_ts'] = $this->beforeTimestamp;
        }

        return [
            'where' => implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    private static function normalizeBound(?string $value, bool $lowerBound, string $field): ?int
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        if (1 === preg_match(self::DATE_PATTERN, $value)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if (false === $day || $day->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException($field.' must be a real calendar date (YYYY-MM-DD or YYYY-MM-DD HH:MM).');
            }

            return ($lowerBound ? $day : $day->modify('+1 day'))->getTimestamp();
        }

        if (1 === preg_match(self::MINUTE_PATTERN, $value)) {
            $minute = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new \DateTimeZone('UTC'));
            if (false === $minute || $minute->format('Y-m-d H:i') !== $value) {
                throw new \InvalidArgumentException($field.' must be a real calendar date (YYYY-MM-DD or YYYY-MM-DD HH:MM).');
            }

            return ($lowerBound ? $minute : $minute->modify('+1 minute'))->getTimestamp();
        }

        throw new \InvalidArgumentException($field.' must be YYYY-MM-DD or YYYY-MM-DD HH:MM.');
    }
}
