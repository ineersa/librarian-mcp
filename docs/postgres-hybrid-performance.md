# PostgreSQL hybrid search: live GIN experiment

Measured **2026-11-16 UTC** on the production deployment at port **8093**, with explicit owner permission to create and remove a temporary index. Application revision: `f80aea0a00994bf09720b15648e5c4f553f88cf7`.

Follow-up: [stored `tsvector` experiment](postgres-stored-tsvector-performance.md) measures a 2.3–2.4× mixed-hybrid improvement on a disposable frozen copy, with unchanged scores. Its same-table baseline is separate from the live-table timings below.

## Scope and method

- Registered project: **Hatfield / agent-core**, project 1, active generation 1; Librarian-owned store table `librarian_p1_g1`.
- **27,109 chunks** before and after the experiment. Production workers remained running.
- PostgreSQL 17 + pgvector; pinned Symfony AI Postgres store **0.15.0.1**.
- Existing indexes: primary-key B-tree and cosine HNSW. No full-text index existed initially.
- Executed through the existing host Castor `prod:console -- dbal:run-sql` facility.
- Used the native hybrid SQL shape: `to_tsvector('english', metadata->>'_text')`, OR-combined `plainto_tsquery` terms, cosine similarity, equal semantic/keyword weights, selection of ID/embedding/metadata/score, weighted-score descending order, and `LIMIT 100`.
- Used one fixed existing 768-dimensional document embedding, fetched by its fixed store ID through a scalar subquery. This is not the question's embedding; it isolates PostgreSQL work with a stable vector. The referenced row still existed at cleanup.
- For each query and phase: one warm-up, then **three measured `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` executions**. Reported timings are PostgreSQL execution time, not Castor/process startup, model calls, network response transfer, or BGE reranking.
- Measured runs reported zero shared blocks read in both phases: these are warm-cache measurements, not disk-cold performance claims.
- Added a temporary matching GIN expression index, `librarian_p1_g1_text_gin_probe`, using a concurrent build. Removed it concurrently in cleanup, then repeated the owner's question once without it.

## Results

All timings below are milliseconds.

| Query | Without GIN: three runs | Median | With GIN: three runs | Median | Speedup |
|---|---|---:|---|---:|---:|
| `CodeRankEmbed` | 345.373, 360.374, 348.364 | 348.364 | 0.553, 0.635, 0.585 | 0.585 | 595.5x |
| `project` | 365.772, 368.999, 364.899 | 365.772 | 33.141, 32.865, 36.294 | 33.141 | 11.0x |
| Owner's full question | 453.703, 443.259, 444.311 | 444.311 | 175.045, 174.068, 175.239 | 175.045 | 2.54x |

Owner's question: `where I was working on upstream issue for opencode provider token usage`.

Matching chunks: **10** for `CodeRankEmbed`, **762** for `project`, and **4,883** for the full question in the GIN plan. Parallel baseline plan row averages round to approximately 4,884 for the last case; this is not evidence of different result membership. `project` was less selective than `CodeRankEmbed`, but still matched only about 2.8% of the table; the full question matched about 18%.

- **Without GIN:** parallel sequential scan, sort, and gather merge. The primary-key lookup in the plan supplies the fixed vector; it does not accelerate hybrid matching/ranking.
- **With GIN:** bitmap index scan on the temporary GIN index, bitmap heap scan, and sort.
- HNSW was not used in either hybrid plan.
- Temporary GIN size: **7,159,808 bytes / 6.83 MiB**.
- Concurrent index creation wall time, including Castor/console overhead: **1.373 seconds**. This is not an isolated DDL execution-time measurement.
- After dropping the index, the owner's question executed in **477.560 ms**, consistent with the original no-GIN baseline.

## Findings

The missing lexical index is a demonstrated performance issue on the current corpus, not merely a hypothetical future scaling concern. GIN gives a substantial improvement without changing retrieval predicates or weighted ranking semantics.

The remaining limitation is real: GIN finds lexical matches efficiently, but PostgreSQL must still compute ranks/vector distances and select the top 100 among those matches. `LIMIT 100` does not cap examined/scored rows. A more widely matching query can therefore remain expensive. The experiment does not establish performance at larger corpus sizes, under load, or for all query distributions, and it does not evaluate search quality or end-to-end MCP latency.

## Implementation location and rollout

The index belongs in **Symfony AI's PostgreSQL `Store::setup()`**, which already owns the store table and vector-index schema. Librarian's `IndexStoreFactory` already calls this for new generation tables.

- Add an idempotent GIN expression index matching the store's actual text expression and configured language, benefiting both text and hybrid queries **in the pinned version tested here**. Do not hard-code English independently of the store's language.
- Keep the existing hybrid query, candidate cap, date predicates, and ranking unchanged. No generated text-vector column, trigger, custom search adapter, or ANN/RRF redesign is necessary for this focused improvement.
- New generation tables are empty and provisioned inside a short transaction. Ordinary index creation there preserves the existing atomic provisioning behavior; do **not** make setup unconditionally use `CONCURRENTLY`, which cannot run inside a transaction block.
- Existing live generation tables need a separate, controlled concurrent backfill outside a transaction. Do not run schema setup on every search or rebuild embeddings to add this index. Use an approved store-schema deployment procedure, not a hand-authored Doctrine migration for dynamic vendor-owned tables.
- Consume an approved package change through Composer rather than editing `vendor/`. No upstream publication is authorized merely by this investigation.

After owner review, the upstream commits were squashed, GPG-signed and submitted as [symfony/ai#2964](https://github.com/symfony/ai/pull/2964). GitHub verified the signature. A package release, dependency updates and production rollout remain pending approval. No new setup option is needed.

### Upstream version difference discovered during implementation

Checked Symfony AI upstream `main` at **`999d5fbeea73cb9bbb3a22b069ee890847655ac7`**. Its hybrid query differs materially from Librarian's locked **`790eaadc`** package:

- It uses `to_tsvector(lang, COALESCE(metadata->>'_text', ''))`, whereas `TextQuery` and the expression measured here omit `COALESCE`. PostgreSQL expression indexes must match the query expression.
- More importantly, it adds the lexical `@@` predicate only when the semantic ratio is zero. Default mixed hybrid queries deliberately retain documents with no keyword match and rank the whole eligible set. Even an expression-matched GIN index cannot accelerate an absent lexical predicate.

Therefore the measured hybrid speedups apply to the pinned package, **not** to current upstream default hybrid search. The upstream patch shares the existing hybrid `COALESCE` expression with `TextQuery` and setup, so its GIN index matches both text and keyword-only hybrid filtering. It does not restore the old lexical gate or change mixed hybrid ranking. The pinned-version patch preserves its existing query SQL and date predicates. No dependency change has been made.

### Upgrade from the backport to upstream

The two patches use the **same deterministic GIN index name for a table but different expressions**. After adopting the backport, a later upstream upgrade must replace the existing non-`COALESCE` GIN index with one matching upstream's `COALESCE` expression. Rerunning `setup()` is insufficient: `IF NOT EXISTS` retains the old index, whose expression does not match the new queries.

For active tables, plan a controlled concurrent replacement outside transaction blocks. This is a future deployment requirement, not automatic setup behavior or an operation performed during this investigation. No source reindexing or embedding rebuild is needed merely to replace the SQL index.

### Review branches and upstream PR

- [Upstream PR #2964](https://github.com/symfony/ai/pull/2964), signed commit `a9ba978b`: focused PHPUnit suite **44 tests / 212 assertions**, PHP CS Fixer clean, package PHPStan passed. Squashing/signing preserved the exact tested source tree. QA remains recorded here; the PR description focuses on the change and current-main measurements below.
- [Pinned-version backport](https://github.com/ineersa/ai-postgres-store/compare/790eaadc...fix/postgres-fulltext-index), commit `4b8ca908`: focused PHPUnit suite **41 tests / 205 assertions**, PHP CS Fixer clean, PHPStan passed with a worktree-local bootstrap/config to resolve the split package's monorepo dependencies. Reflection verified the tests loaded this exact pinned `Store.php`.

Short table names retain `<table>_text_gin`. When that exceeds PostgreSQL's default 63-byte identifier limit, both patches use a SHA-256 prefix of the full table name plus the distinguishing `_text_gin` suffix, fitting within 63 bytes without splitting UTF-8. The focused setup test checks the reported 57-byte/`text` and 62-byte/`embedding` cases, bounded deterministic names and distinct names for tables sharing a long prefix. Existing vector-index naming is unchanged. No database integration suite was run for these code patches. The owner subsequently requested the current-main timing rerun below.

## Rerun using current upstream main — 2026-11-16

Fetched upstream `main` again: still **`999d5fbeea73cb9bbb3a22b069ee890847655ac7`**. Executed its mixed-hybrid SQL shape, then compared its `TextQuery` SQL before the PR with the PR's matching `COALESCE` expression and GIN index. This used the existing Castor production console, not a dependency upgrade or replacement of the running application.

The same active store now contained **27,164 chunks**, unchanged before/after each probe. Each query/phase had one warm-up and three timed `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` executions. All queries used `LIMIT 100` and the same OR-combined input words as the original experiment. The mixed query retained equal semantic/keyword weights, cosine distance, and the same fixed document vector loaded through a primary-key subquery. Text queries do not use a query vector. Times below are median database execution milliseconds, not model/network/reranking latency.

### Mixed hybrid search: no demonstrated GIN benefit

| Search text | Without GIN | With matching GIN |
|---|---:|---:|
| `CodeRankEmbed` | 428.156 | 419.232 |
| `project` | 439.528 | 418.353 |
| Full OpenCode/token-usage question quoted above | 453.674 | 427.470 |

Both phases scanned all **27,164** chunks using a parallel sequential scan, sort and gather merge. Neither used GIN or HNSW. The primary-key index in the plan only loaded the fixed query vector. The query has no lexical `@@` filter. Small timing differences are not evidence of an index speedup. Some mixed-query runs reported shared block reads despite the warm-up, and the database remained live; this is not a controlled cold/warm-cache or load benchmark.

### Text search: substantial improvement

| Search text | Main before PR | PR query with GIN |
|---|---:|---:|
| `CodeRankEmbed` | 368.867 | 0.386 |
| `project` | 387.318 | 29.332 |
| Full OpenCode/token-usage question quoted above | 437.619 | 160.542 |

The baseline used main's non-`COALESCE` text expression without GIN. The after phase used the PR's `COALESCE` text expression and its matching GIN. Both preserve the lexical filter and `ts_rank` ordering. Plans changed from sequential scans to GIN bitmap index/heap scans and sort. All measured text-query runs reported zero shared blocks read. The index accelerates finding matches; broader matches still require scoring and sorting.

Both temporary `COALESCE` GIN indexes were valid, occupied **7,168,000 bytes** each, and were removed concurrently. Cleanup after each probe verified its index absent, unchanged chunk count, the same active generation, and the fixed vector still present. No application/source rows, settings or journals were changed by these probes. Local metrics remain in ignored `var/qa/gin-probe-main/result.json` and `var/qa/gin-probe-main-text/result.json`; throwaway drivers remain outside the repository.

## Cleanup and artifacts

Cleanup queried PostgreSQL and confirmed the temporary index was absent. The chunk count remained **27,109**, and the fixed-vector row remained present. No application/source rows, OM settings, journals, authentication settings, or tracked application code were changed by the experiment.

Detailed local execution metrics remain in ignored `var/qa/gin-probe/result.json`. This document preserves the sanitized measurements and conclusions without committing source content, credentials, or embedding values. The throwaway driver remains outside the repository; no benchmark suite was added.

## References

- [PostgreSQL 17: creating matching full-text expression indexes](https://www.postgresql.org/docs/17/textsearch-tables.html#TEXTSEARCH-TABLES-INDEX)
- [PostgreSQL 17: GIN is the preferred full-text index type](https://www.postgresql.org/docs/17/textsearch-indexes.html)
- [PostgreSQL 17: identifier limits are measured in bytes](https://www.postgresql.org/docs/17/sql-syntax-lexical.html#SQL-SYNTAX-IDENTIFIERS)
- [PostgreSQL 17: CREATE INDEX IF NOT EXISTS does not check index equivalence](https://www.postgresql.org/docs/17/sql-createindex.html)
- [pgvector: ordering directly by distance is required for vector index use](https://github.com/pgvector/pgvector#why-isnt-a-query-using-an-index)
- Installed `vendor/symfony/ai-postgres-store/Store.php`: `setup()`, `queryText()`, and `queryHybrid()`; `src/Index/Store/IndexStoreFactory.php` and `src/Index/Search/HybridSearch.php`.
