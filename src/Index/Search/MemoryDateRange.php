<?php

declare(strict_types=1);

namespace App\Index\Search;

use App\Index\IndexFields;

/**
 * OM-compatible memory date window for native hybrid candidate filtering.
 *
 * Typed bounds are prepared at the MCP boundary using Symfony validation/serialization.
 * The lower bound is inclusive; the upper bound is already exclusive.
 */
final readonly class MemoryDateRange
{
    public function __construct(
        public ?\DateTimeImmutable $after,
        public ?\DateTimeImmutable $beforeExclusive,
    ) {
        // Exclusive upper bounds make an empty window when after >= beforeExclusive.
        if (null !== $after && null !== $beforeExclusive && $after >= $beforeExclusive) {
            throw new \InvalidArgumentException('after must be less than or equal to before.');
        }
    }

    public function isEmpty(): bool
    {
        return null === $this->after && null === $this->beforeExclusive;
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

        if (null !== $this->after) {
            $clauses[] = \sprintf("(metadata->>'%s')::bigint >= :memory_after_ts", $field);
            $params['memory_after_ts'] = $this->after->getTimestamp();
        }
        if (null !== $this->beforeExclusive) {
            $clauses[] = \sprintf("(metadata->>'%s')::bigint < :memory_before_ts", $field);
            $params['memory_before_ts'] = $this->beforeExclusive->getTimestamp();
        }

        return [
            'where' => implode(' AND ', $clauses),
            'params' => $params,
        ];
    }
}
