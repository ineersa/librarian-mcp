# Main-based PostgreSQL store deployed in Librarian

Current package: **`dev-development` at `d03d231`**, retaining stored vectors and GIN but reverting the [deferred-fetch experiment](#deferred-document-fetching-experiment). The initial stored-vector rollout is recorded first, followed by the rejected experiment's comparison. The stored-vector change is submitted as one signed commit `dba6b38f` in [upstream PR #2967](https://github.com/symfony/ai/pull/2967).

## Scope correction

**Use these results for reviewing the upstream stored-`tsvector` change.** The earlier `0.15.0.2` deployment was a mistaken backport onto Librarian's old keyword-gated implementation. Its 1/11/25 ms results do **not** measure current-main mixed hybrid search and must not be used to justify the upstream PR.

**Librarian itself now runs the main-based development branch on port 8093.** These measurements use the deployed application, not the earlier isolated Composer runtime or a backport.

## Exact code and runtime — initial stored-vector rollout

- Baseline: upstream `main` at **`999d5fbeea73cb9bbb3a22b069ee890847655ac7`**.
- Package at this rollout: [`ineersa/ai-postgres-store`, `development`](https://github.com/ineersa/ai-postgres-store/tree/development), **`399c78f843731d2f1b1d3264deb33733c711fa71`**. This merges upstream split main `da91fb8` with the GIN/stored-tsvector branch ancestry and retains parameterized date filters without restoring a keyword gate.
- Application Composer constraint: **`symfony/ai-postgres-store: dev-development`**, locked at that revision. Runtime AI platform/store and Generic/Voyage bridges are **1.0.0-BETA1**. Global minimum stability remains stable, with package-scoped allowances only; Mate/MCP/framework selections are unchanged.
- Application integration/deployment commit: **`f2eb51d`** on `feat/postgres-gin-index`. `IndexStoreFactory` uses main's `Metric::Cosine`, constructor dimensions and parameterless `setup()`.
- The probe loads **`/app/vendor/autoload.php`**, boots the production application kernel, obtains its Doctrine connection and constructs the store through **`/app/src/Index/Store/IndexStoreFactory.php`**. The measured optimized class is **`/app/vendor/symfony/ai-postgres-store/Store.php`**. Composer version/reference and reflection/source hashes are checked on every invocation. The baseline invocation loads the unmodified upstream-main Store file as a control, with the same application dependencies and factory.
- At this rollout, both web and Messenger containers reported `dev-development` at `399c78f`; neither used the old 0.15 backport.
- Split-package checks: **47 tests / 239 assertions**, CS Fixer clean and PHPStan passed. Application checks: **5 headless browser journeys / 101 assertions**, `cs:fix` and `qa:static` passed. Production configuration, build/up, restart and service-status tasks passed through Castor.

## Live schema

Target: **`public.librarian_p1_g1`**, verified active agent-core generation, **27,384 rows** throughout measurement.

The installed package's real `Store::setup()` supplies the schema through the application factory. Both existing generation tables now have the main-compatible representation and GIN:

```sql
search_tsv tsvector GENERATED ALWAYS AS (
    to_tsvector('english', COALESCE(metadata->>'_text', ''))
) STORED
```

The agent-core generation already had this exact expression; idempotent setup took **5.47 ms**. The finance generation's prior non-`COALESCE` column was explicitly rebuilt in one transaction through installed-package setup (**1.19 s**). Both retain primary key, HNSW and valid GIN on `search_tsv`. The owner-retained frozen table is untouched at **27,288 rows**. Existing embeddings were not rebuilt.

## Results

Actual `Store::query(new HybridQuery(...), ['limit' => 100])` calls with cosine distance, equal weights, OR-combined terms and the same fixed existing query vector. Neither implementation imposes a keyword-match predicate.

One warm-up per variant, followed by five alternating baseline/candidate invocations. The table and schema were the same for both variants. Median times include PHP/PDO fetch and document hydration, but exclude embedding generation, reranking and HTTP transport.

| Input | Upstream main control | Installed development branch | Speedup |
|---|---:|---:|---:|
| `CodeRankEmbed` | 284.514 ms | **92.452 ms** | 3.08× |
| `project` | 277.871 ms | **75.192 ms** | 3.70× |
| Full OpenCode question | 282.498 ms | **95.160 ms** | 2.97× |

Question: `where I was working on upstream issue for opencode provider token usage`.

Both variants returned **100 documents for every input**, including the rare term for which the old keyword-gated bridge returned only 10. Sorted `(id, score)` fingerprints matched across every baseline/candidate measured invocation for all three queries. No memory content or embedding values are included in this report.

The baseline is lower than the original 400+ ms measurements. This run took place after live-table generated-column rewrites, with different physical layout/execution conditions. The supported speedup claim is this **paired comparison on the same live table**, not 450 ms from a different phase versus 75 ms here. GIN is installed, but the mixed query still has no lexical filter for GIN to accelerate; the stored text representation is the substantive query change.

## Application state and artifacts

Actual production MCP `memory_search` was called for all three inputs after deployment: each returned HTTP 200, `ok: true` and five results. Single end-to-end samples were **644 ms**, **384 ms** and **400 ms** respectively, including embedding, retrieval, BGE reranking and HTTP transport; these are smoke checks, not latency medians. Anonymous authentication mode and existing registrations were preserved. No new Symfony AI PR was opened or updated.

Sanitized measurement, setup and MCP evidence is retained in ignored `var/qa/deployed-store/{result,setup,mcp}.json`. The probe is `var/qa/deployed-store-probe.php`; it uses only the real application's autoloader and dependencies. The frozen PostgreSQL table remains available for further experiments. No registered source tree, OM database, journal or embedding was rewritten.

## Deferred document fetching experiment

At the owner's request, created a separate feature branch, merged it into `development`, updated the actual application's Composer lock and deployed it to **8093**:

- Feature commit: [`2956cd5ea4b2d61c277cbdf1309e2dbd23e4de3b`](https://github.com/ineersa/ai-postgres-store/commit/2956cd5ea4b2d61c277cbdf1309e2dbd23e4de3b); the `perf/deferred-document-fetch` branch was subsequently removed.
- Development merge: **`4f902d18b2cf517bc9c499fd9b63c734139e5086`**; application deployment commit **`30c1c5e`**.
- Inner query still scores every eligible row and selects top-N `id, score`. The outer primary-key join fetches embedding and metadata for those IDs, retaining final score ordering. No ANN/candidate pruning, changed weights, new keyword gate or schema change. Date predicates and `maxScore` remain before the inner limit.

Paired comparison against the previous **`399c78f` stored-vector implementation**, on the same live agent-core table with **27,474 rows**. Same three inputs and fixed vector, one warm-up per variant, five alternating runs. Both variants use the actual application's autoloader, production kernel connection and factory; the control loads the exact pre-change Store file. Reflection/hash and installed revision checks verify the loaded implementation. No isolated Composer runtime.

| Input | Before deferred fetch | After deferred fetch | Change |
|---|---:|---:|---:|
| `CodeRankEmbed` | 95.325 ms | 93.329 ms | 2.1% faster |
| `project` | 76.803 ms | 74.019 ms | 3.6% faster |
| Full OpenCode question | 90.150 ms | 93.350 ms | 3.5% slower |

These are median native store-call times, excluding model/reranking/HTTP costs. Sample ranges overlap for every query. Every invocation returned 100 documents; sorted `(id, score)` fingerprints matched across all measured baseline/candidate runs.

**Conclusion: no clear latency improvement on this corpus.** The intended plan change works, but does not establish that the extra join is worth including in an upstream performance patch.

`EXPLAIN (ANALYZE, BUFFERS, SETTINGS, TIMING OFF)` on the actual captured package query confirms, for the question:

- Before: full scan → top-N heapsort → limit; estimated sort row width **665 bytes**, sort memory **184 KiB**.
- After: full scan → narrow top-N heapsort → limit → **100 primary-key index lookups**; sort width **24 bytes**, sort memory **36 KiB**.
- Both still score **27,474 rows**, and neither spills to temporary storage. Single plan executions were **83.29 ms** and **83.87 ms**. Shared-block reads remain present, so these are live-system observations, not controlled CPU-only results.

Checks: package **47 tests / 239 assertions**, PHPStan and CS Fixer passed; application **5 headless browser journeys / 101 assertions**, `cs:fix` and `qa:static` passed. Production Castor build/up/restart passed. Web and Messenger both have the development merge. Production MCP searches for all three inputs returned HTTP 200, `ok: true`, five results each (single end-to-end samples **643 / 403 / 395 ms**, not benchmark medians).

The owner rejected the experiment after these results. Merge `4f902d1` was reverted at **`d03d231`**, restoring the package sources from `399c78f`, and its feature branch was removed. The application remains on the fork's `dev-development` branch; no database/schema rollback is required. The frozen table remains untouched at **27,288 rows**. Local evidence is under ignored `var/qa/deferred-fetch/`: `result.json`, `mcp.json`, and sanitized `*-plan-summary.json`; raw plans remain private because they may contain query-vector literals.
