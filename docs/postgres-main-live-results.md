# Main-based PostgreSQL store: actual package on the live table

## Scope correction

**Use these results for reviewing the upstream stored-`tsvector` change.** The earlier `0.15.0.2` deployment was a mistaken backport onto Librarian's old keyword-gated implementation. Its 1/11/25 ms results do **not** measure current-main mixed hybrid search and must not be used to justify the upstream PR.

This comparison executes the actual main-based PHP store on the live agent-core table. It is not SQL emulation and does not use the 0.15 bridge or its mandatory lexical gate.

## Exact code and runtime

- Baseline: upstream `main` at **`999d5fbeea73cb9bbb3a22b069ee890847655ac7`**.
- Candidate: [review branch `perf/postgres-stored-tsvector`](https://github.com/ineersa/ai/compare/a9ba978bf06fc47bd90623ac17c75c0fc9768ada...perf/postgres-stored-tsvector), final commit **`2da4a115467c3bee1cac40ff7df433154d0cb534`**, building on the GIN patch and the same upstream base.
- Main's Postgres package requires Symfony AI platform/store **1.0**, whereas Librarian currently uses 0.15. Created a separate Composer runtime using exact archived main platform/store sources and the reviewed Postgres package, with their unmodified dependency requirements. Path-repository versions identify these development snapshots as `1.0.x-dev`; the runtime's global minimum stability remains stable.
- Installed dependencies through Castor in ignored `var/qa/main-store-runtime`, then ran that isolated runtime inside the verified production PHP container against its existing PostgreSQL connection. No application autoload or 0.15 AI classes were loaded into the benchmark.
- Reflection verified the actual loaded files and SHA-256 hashes on every invocation:
  - baseline Store: `a4d1e1b35130851be821a37a6774b92afeb12dfcb5167b9a1b41b02d7fd3d9a6`
  - candidate Store: `fb0db39c3b1bbde76f4d932fcccbc1e11e4d457a73df22ef0c19c8eb5e28ed89`
- Existing upstream review-branch checks remain: **46 tests / 227 assertions**, CS Fixer clean and PHPStan passed. No source change was needed for this live execution.

## Live schema

Target: **`public.librarian_p1_g1`**, verified active agent-core generation, **27,316 rows** throughout measurement.

The main-based package's real `Store::setup()` supplied the schema. Replaced the previously installed backport's non-`COALESCE` generated column explicitly, in one transaction, then called candidate setup to create the exact upstream representation and GIN:

```sql
search_tsv tsvector GENERATED ALWAYS AS (
    to_tsvector('english', COALESCE(metadata->>'_text', ''))
) STORED
```

This setup took **9.76 seconds** including the backfill/rewrite and indexes. The primary key, HNSW and valid GIN on `search_tsv` remain installed. The owner-retained frozen table is untouched at **27,288 rows**.

## Results

Actual `Store::query(new HybridQuery(...), ['limit' => 100])` calls with cosine distance, equal weights, OR-combined terms and the same fixed existing query vector. Neither implementation imposes a keyword-match predicate.

One warm-up per variant, followed by five alternating baseline/candidate invocations. The table and schema were the same for both variants. Median times include PHP/PDO fetch and document hydration, but exclude embedding generation, reranking and HTTP transport.

| Input | Actual main Store | Main + stored vector + GIN | Speedup |
|---|---:|---:|---:|
| `CodeRankEmbed` | 288.161 ms | **96.948 ms** | 2.97× |
| `project` | 280.079 ms | **76.240 ms** | 3.67× |
| Full OpenCode question | 291.329 ms | **95.254 ms** | 3.06× |

Question: `where I was working on upstream issue for opencode provider token usage`.

Both variants returned **100 documents for every input**, including the rare term for which the old keyword-gated bridge returned only 10. Sorted `(id, score)` fingerprints matched across every baseline/candidate measured invocation for all three queries. No memory content or embedding values are included in this report.

The baseline is lower than the original 400+ ms measurements. This run took place after live-table generated-column rewrites, with different physical layout/execution conditions. The supported speedup claim is this **paired comparison on the same live table**, not 450 ms from a different phase versus 76 ms here. GIN is installed, but the mixed query still has no lexical filter for GIN to accelerate; the stored text representation is the substantive query change.

## Application state and artifacts

This is an **isolated main-based package benchmark against live data**, not a claim that Librarian's entire AI stack has been upgraded to 1.0. The running application still uses the earlier `0.15.0.2` deployment; that mistaken backport is explicitly separate from these upstream results. Production MCP retrieval was checked after schema replacement: HTTP 200, successful search, five results. No new Symfony AI PR was opened or updated.

The main-based runtime and exact source archives are retained locally in ignored `var/qa/main-store-runtime/`, including its own Composer lock. Sanitized results and source/runtime verification are in `live-main-package-result.json`; setup evidence is in `setup.json`. The frozen PostgreSQL table remains available for further experiments. No registered source tree, OM database, journal or embedding was rewritten.
