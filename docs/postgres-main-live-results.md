# Main-based PostgreSQL store deployed in Librarian

## Scope correction

**Use these results for reviewing the upstream stored-`tsvector` change.** The earlier `0.15.0.2` deployment was a mistaken backport onto Librarian's old keyword-gated implementation. Its 1/11/25 ms results do **not** measure current-main mixed hybrid search and must not be used to justify the upstream PR.

**Librarian itself now runs the main-based development branch on port 8093.** These measurements use the deployed application, not the earlier isolated Composer runtime or a backport.

## Exact code and runtime

- Baseline: upstream `main` at **`999d5fbeea73cb9bbb3a22b069ee890847655ac7`**.
- Deployed package: [`ineersa/ai-postgres-store`, `development`](https://github.com/ineersa/ai-postgres-store/tree/development), **`399c78f843731d2f1b1d3264deb33733c711fa71`**. This merges upstream split main `da91fb8` with the GIN/stored-tsvector branch ancestry and retains parameterized date filters without restoring a keyword gate.
- Application Composer constraint: **`symfony/ai-postgres-store: dev-development`**, locked at that revision. Runtime AI platform/store and Generic/Voyage bridges are **1.0.0-BETA1**. Global minimum stability remains stable, with package-scoped allowances only; Mate/MCP/framework selections are unchanged.
- Application integration/deployment commit: **`f2eb51d`** on `feat/postgres-gin-index`. `IndexStoreFactory` uses main's `Metric::Cosine`, constructor dimensions and parameterless `setup()`.
- The probe loads **`/app/vendor/autoload.php`**, boots the production application kernel, obtains its Doctrine connection and constructs the store through **`/app/src/Index/Store/IndexStoreFactory.php`**. The measured optimized class is **`/app/vendor/symfony/ai-postgres-store/Store.php`**. Composer version/reference and reflection/source hashes are checked on every invocation. The baseline invocation loads the unmodified upstream-main Store file as a control, with the same application dependencies and factory.
- Both web and Messenger containers report `dev-development` at `399c78f`; neither uses the old 0.15 backport.
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
