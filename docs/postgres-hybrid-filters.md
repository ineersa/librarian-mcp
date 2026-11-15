# PostgreSQL hybrid date filters

Temporary dependency note for the MCP memory-search date-parameter prerequisite.

## Official store gap

Locked `symfony/ai-postgres-store` **0.15.0** (`9658c2ec`) already accepts parameterized `where` / `params` for `VectorQuery`. `HybridQuery` did not apply those options before `ORDER BY` / `LIMIT`, so date predicates could not honestly restrict hybrid candidates.

The split package README points contributions to the monorepo:

- Report issues / send PRs in https://github.com/symfony/ai
- Not against the read-only split `symfony/ai-postgres-store`

## Upstream submission

Focused monorepo PR opened against `symfony/ai` `main`:

- PR: https://github.com/symfony/ai/pull/2950
- Head: `ineersa:feat/postgres-hybrid-where-params`
- Commit: `5242e8ea`
- Path: `src/store/src/Bridge/Postgres/Store.php`
- Same minimal HybridQuery `where` / `params` parity as VectorQuery, plus changelog/docs and one positive existing unit-test assertion

No reviewers were requested manually; GitHub applied the upstream repository's CODEOWNERS review requests automatically. Waiting on upstream review/merge/release before Librarian can drop the temporary pin.

## Temporary reviewed pin still in use

Until an upstream release includes the change, this checkout pins a reviewed fork tag through Composer:

- Temporary package fork: https://github.com/ineersa/ai-postgres-store
- Branch: `feat/hybrid-where-params`
- Commit: `790eaadc969c11db0a8d531d3e02443d249d71ec`
- Annotated tag / constraint: `0.15.0.1`
- VCS repository: `https://github.com/ineersa/ai-postgres-store.git`
- Global `minimum-stability` remains `stable`
- No `vendor/` edits and no duplicated Store class in application code

The temporary fork change matches the monorepo PR behavior: when `where` is present it is AND-combined in parentheses with the hybrid lexical clause, and `params` are merged into the bound parameter map before execute.

Direct PR creation against `symfony/ai-postgres-store` remains blocked (`CreatePullRequest` permission denied), which matches the split-repo contribution guidance above.

## Application integration

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
- Public MCP limits remain 1–50 (default 20); the existing admin default remains 10. Truncation is detected by observing one additional distinct parent before slicing
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
