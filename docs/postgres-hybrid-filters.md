# PostgreSQL hybrid date filters

Temporary dependency note for the MCP memory-search date-parameter prerequisite.

## Official store gap

Locked `symfony/ai-postgres-store` **0.15.0** (`9658c2ec`) already accepts parameterized `where` / `params` for `VectorQuery`. `HybridQuery` did not apply those options before `ORDER BY` / `LIMIT`, so date predicates could not honestly restrict hybrid candidates.

## Temporary reviewed fork

Until an upstream release includes the same change, this checkout pins a reviewed fork tag through Composer:

- Fork: https://github.com/ineersa/ai-postgres-store
- Branch: `feat/hybrid-where-params`
- Commit: `790eaadc969c11db0a8d531d3e02443d249d71ec`
- Annotated tag / constraint: `0.15.0.1`
- VCS repository: `https://github.com/ineersa/ai-postgres-store.git`
- Global `minimum-stability` remains `stable`
- No `vendor/` edits and no duplicated Store class in application code

The fork change mirrors VectorQuery: when `where` is present it is AND-combined in parentheses with the hybrid lexical clause, and `params` are merged into the bound parameter map before execute.

An upstream PR could not be opened automatically from this account (`CreatePullRequest` permission denied against `symfony/ai-postgres-store`). The fork branch/tag remain the provenance source.

## Application API for main

`App\Index\Search\MemoryDateRange::fromInputs(?string $after, ?string $before): self`

- Accepts OM formats `YYYY-MM-DD` or `YYYY-MM-DD HH:MM`
- Blank / whitespace optional values are absent
- Validates real calendar dates/minutes
- Requires `after <= before` when both are present
- Stores UTC unix timestamps: inclusive lower bound, exclusive start of next day/minute upper bound
- `toQueryOptions()` returns only fixed parameterized predicates against `IndexFields::OCCURRED_AT`
- Throws `\InvalidArgumentException` with a safe message on invalid input

`App\Index\Search\HybridSearch::search(int $projectId, int $generationId, string $question, int $limit = 10, ?MemoryDateRange $dateRange = null): SearchResult`

- First four arguments unchanged for admin callers
- Optional fifth argument applies date filtering inside PostgreSQL before the candidate cap, BGE rerank, and parent collapse
- `$limit` allows up to **51** so main can request `limit + 1` for truncation detection; admin default remains 10
- No caller-provided SQL

`App\Index\Search\SearchResult`

- `results`: collapsed parent hits
- `candidateTruncated`: hybrid store returned the full candidate cap (`100`)
- `resultTruncated`: more distinct parents existed than `$limit`
- `truncated`: `candidateTruncated || resultTruncated` (preserves existing admin flag)

`App\Index\IndexException`

- `QUERY_EMBEDDING_FAILED = 1`
- `QUERY_RETRIEVAL_FAILED = 2`
- `QUERY_TIMEOUT = 3`
- `sanitized(string $message, ?Throwable $previous = null, int $code = 0)`
- HybridSearch maps embedding failures to `QUERY_EMBEDDING_FAILED`, retrieval/rerank failures to `QUERY_RETRIEVAL_FAILED`, and native `TimeoutExceptionInterface` (including wrapped previous chains) to `QUERY_TIMEOUT`
- Provider bodies are not chained

Existing indexed `occurred_at` numeric metadata is reused; no schema migration or reindex.
