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

Until a compatible upstream release includes the required changes, this checkout pins a reviewed fork commit through Composer:

- Temporary package fork: https://github.com/ineersa/ai-postgres-store
- Branch: `perf/stored-tsvector`
- Commit: `dabcaadbc326b460db948ba545e53f10aeb5d79d`
- Annotated tag / exact constraint: `0.15.0.2`, locked to the commit above
- VCS repository: `https://github.com/ineersa/ai-postgres-store.git`
- Global `minimum-stability` remains `stable`; no development stability allowance is needed
- No `vendor/` edits and no duplicated Store class in application code

This extends the original `0.15.0.1` / `790eaadc` filter fork with the owner-approved GIN and stored-text-vector optimization. The original filter behavior remains: when `where` is present it is AND-combined in parentheses with the hybrid lexical clause, and `params` are merged into the bound parameter map before execute. The 0.15 mandatory lexical filter, null semantics, configured language, ranking formula and setup API remain unchanged; this is not an upgrade to current upstream mixed-hybrid semantics.

The fork's `setup()` creates/backfills a stored generated `search_tsv` using `to_tsvector(lang, metadata->>'_text')` and creates a matching `*_search_tsv_gin` index. Queries read that column. Existing generation tables must receive this schema upgrade before application deployment; normal `IndexStoreFactory` provisioning handles new generations. Adding the column takes an exclusive lock and backfills rows. Changing its language requires rebuilding the representation. Prior expression GIN indexes are not automatically dropped by the package. See [performance report](postgres-stored-tsvector-performance.md).

Direct PR creation against `symfony/ai-postgres-store` remains blocked (`CreatePullRequest` permission denied), which matches the split-repo contribution guidance above.

## Application integration

`App\Index\Search\MemoryDateRange::__construct(?\DateTimeImmutable $after, ?\DateTimeImmutable $beforeExclusive)`

- MCP's native `#[Schema]` validates the wire formats `YYYY-MM-DD` or `YYYY-MM-DD HH:MM`; there is no second date-pattern regex in application code.
- At the MCP boundary, Symfony's `DateTime` constraint checks calendar validity and the Serializer's `DateTimeNormalizer` converts the input using an explicit format and UTC timezone.
- The boundary retains day/minute precision, expanding the inclusive upper bound to the start of the next day/minute.
- The value object holds typed immutable dates and rejects empty/reversed ranges; it does not parse strings or duplicate calendar validation.
- `toQueryOptions()` converts the bounds to Unix timestamps and returns only fixed parameterized predicates against `IndexFields::OCCURRED_AT`.

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

Date filtering reuses existing indexed `occurred_at` numeric metadata. The stored-vector follow-up changes only Librarian-owned store schema through the package's setup, not Doctrine metadata or source databases; no embedding rebuild is needed.
