<?php

declare(strict_types=1);

namespace App\Index\Search;

/**
 * Bounded hybrid search result after RRF and BGE reranking.
 *
 * @phpstan-type Hit array{
 *     project_id: int,
 *     generation_id: int,
 *     kind: string,
 *     source_id: string,
 *     session_id: string,
 *     occurred_at: int,
 *     content_hash: string,
 *     chunk_position: int,
 *     snippet: string,
 *     rerank_score: float
 * }
 */
final readonly class SearchResult
{
    /**
     * @param list<Hit> $results
     */
    public function __construct(
        public array $results,
        public bool $truncated,
    ) {
    }
}
