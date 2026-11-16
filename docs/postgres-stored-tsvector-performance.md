# Stored PostgreSQL text vector experiment

Measured on 2026-11-16. The initial sections record a review-branch experiment; the later [owner-authorized live installation](#owner-authorized-live-fork-installation-and-measurement) records the actual fork dependency upgrade, retained GIN indexes, live measurements and deployment on port 8093. No new Symfony AI PR was opened.

## Change under test

Extend the upstream GIN patch (`a9ba978b`, based on upstream `999d5fbe`) with a PostgreSQL **stored generated column**:

```sql
search_tsv tsvector GENERATED ALWAYS AS (
    to_tsvector('english', COALESCE(metadata->>'_text', ''))
) STORED
```

The package implementation uses its configured language, not a separately hard-coded language choice. `TextQuery` and `HybridQuery` read this column instead of parsing each document during every search. PostgreSQL maintains it on inserts and updates. GIN indexes the stored column for lexical filtering; exhaustive mixed hybrid ranking still scans all eligible rows.

This preserves the current mixed-hybrid behavior: no mandatory keyword match, equal weights in this experiment, cosine similarity, the existing `ts_rank` calculation, and the same OR-combined query terms. No candidate truncation or rank-fusion algorithm was introduced.

## Safe comparison

- Source: existing agent-core registration, active project 1 / generation 1, Librarian-owned `librarian_p1_g1`.
- Verified source identity, active generation, fixed embedding availability and absence of the experiment's destination before writing anything.
- Copied **27,268 rows** into a dedicated **unlogged, non-temporary table**, `librarian_tsvector_probe_20261116`, then added a primary key and the generated column. Source data and schema were not changed.
- An unlogged table permits parallel scans, unlike a temporary table. Both query variants ran on the **same frozen copied relation**, including its generated column, with fresh statistics.
- Compared on-demand `to_tsvector(...)` versus `search_tsv`. Both selected ID, embedding, metadata and weighted score, ordered by score descending and used `LIMIT 100`.
- Reused the same fixed existing document embedding as the previous experiments, fetched through a primary-key scalar subquery. It is not an embedding generated for each question.
- One warm-up per variant, then **five alternating runs** per query, reversing which variant ran first on alternate iterations.
- Used `EXPLAIN (ANALYZE, BUFFERS, VERBOSE, SETTINGS, TIMING OFF, FORMAT JSON)`. Also captured one node-timed execution per variant/query for inspection.
- All database operations ran through the existing Castor `prod:console -- dbal:run-sql` facility. No new benchmark suite or tooling task was added.

## Mixed-hybrid results

Median PostgreSQL execution milliseconds, excluding model generation, network transfer, output serialization and application reranking:

| Search | On-demand text vector | Stored text vector | Speedup |
|---|---:|---:|---:|
| `CodeRankEmbed` | 285.682 | 124.399 | 2.30× |
| `project` | 284.478 | 116.941 | 2.43× |
| Full question below | 293.489 | 128.187 | 2.29× |

Question: `where I was working on upstream issue for opencode provider token usage`, supplied as OR-combined words, not an exact phrase.

The five measured runs were:

| Search | On demand | Stored |
|---|---|---|
| `CodeRankEmbed` | 285.682, 296.878, 280.487, 289.078, 277.935 | 125.924, 127.549, 122.028, 118.691, 124.399 |
| `project` | 287.306, 284.478, 282.337, 276.970, 285.459 | 111.787, 114.602, 116.941, 127.381, 116.964 |
| Full question | 296.120, 289.040, 295.492, 290.126, 293.489 | 125.900, 127.195, 135.035, 131.992, 128.187 |

**Do not compare 420–450 ms from the earlier live-table experiment directly against 117–128 ms here.** This fresh copy's on-demand baseline was itself faster. The corpus, physical relation, measurement conditions and instrumentation differ; the earlier experiment did not establish a controlled baseline for this one. The demonstrated speedup is the paired same-table comparison above.

### Plan observations

- On-demand queries used parallel sequential scans with two planned/launched workers, top-N heapsort and gather merge.
- Stored-column queries used a nonparallel sequential scan and top-N heapsort. No planner settings were forced; removing text conversion changed the selected plan as well as per-row work.
- No GIN index existed during this mixed-query comparison. The primary-key index only supplied the fixed vector. No HNSW index was built on the copy; the earlier exhaustive mixed-query plans did not use it either.
- Sorts fit in memory; no temporary block reads/writes were reported. No JIT section was reported.
- Shared-buffer reads occurred despite warm-up. This is not a controlled CPU-only or fully buffer-resident benchmark. A shared-buffer read does not by itself establish physical disk I/O.

## Correctness and storage

- Every stored vector equaled the original `to_tsvector` expression.
- For all three inputs, comparing lexical scores across **every row** found **zero differences**. The semantic expression was unchanged.
- Comparing top-100 `(id, score)` sets found **zero differences**, using the same secondary ID ordering on both equivalence queries to resolve ties. Timing queries retained the original score-only ordering.
- Copied table plus primary key occupied **133,218,304 bytes** before the column and **145,326,080 bytes** afterward: a net increase of **12,107,776 bytes**, about **11.55 MiB / 9.1%** on this copy. This includes effects of the table rewrite, not just the standalone size of vector values.
- Adding/backfilling the column took **1.34 seconds including Castor/console overhead**. This is not an isolated DDL timing or a promise about locking on a production table.
- After the timing comparison, a GIN index on the stored column built successfully and was valid, occupying **7,340,032 bytes / 7 MiB**. No additional text-query timing matrix was run.

## Cleanup and limits

The disposable table and its indexes were dropped. Cleanup verified the table absent, the source generation still active and its row count still **27,268**. No live source/index table was altered, no embeddings were rebuilt, and no settings, OM databases or journals were modified.

Sanitized execution metrics are local and ignored at `var/qa/stored-tsvector-probe/result.json`; the throwaway driver was outside the repository. Private memory contents and embedding values are not included in this report.

This establishes a useful **2.3–2.4× reduction in exhaustive mixed-query database execution** for these inputs, without changing scores. It does not establish end-to-end latency, throughput under load, or performance on larger corpora. Scanning all rows, reading vectors and ranking remain costs.

Existing-table rollout must be explicit: adding a stored generated column rewrites/backfills the table and requires locking. Its configured language becomes part of the persisted representation. Reusing a table with a different language requires rebuilding that representation, not merely changing the query's language option. These deployment concerns are documented in the package branch; nothing has been deployed here.

## Review branch and checks

- [Review only the stored-vector changes on top of the GIN patch](https://github.com/ineersa/ai/compare/a9ba978bf06fc47bd90623ac17c75c0fc9768ada...perf/postgres-stored-tsvector).
- Branch: `ineersa/ai:perf/postgres-stored-tsvector`; final commit [`2da4a115`](https://github.com/ineersa/ai/commit/2da4a115467c3bee1cac40ff7df433154d0cb534).
- Focused package PHPUnit: **46 tests / 227 assertions passed**.
- PHP CS Fixer dry-run: **0 files requiring fixes**.
- Package PHPStan, level 8 and existing package rules: **no errors**.
- Checks reran after the final code/test/documentation follow-up. Reused dependency tooling with a worktree-specific autoload/bootstrap so tests and analysis targeted the modified source. No additional suppressions or baseline were added.
- The package's PostgreSQL integration suite was **not run** because that QA PHP runtime lacked PDO PostgreSQL. The actual PostgreSQL experiment above separately verified generated-column backfill, equality of computed scores/results and GIN creation; it does not claim to exercise the entire PHP package integration lifecycle.

No Symfony AI PR was created or updated. The existing GIN PR branch remains unchanged. Librarian's dependency pin and running deployment are unchanged.

## Contemporaneous live-table control and retained snapshot

At the owner's request, repeated the **current-main mixed-hybrid baseline on the original live table**, interleaved with the two frozen-copy variants. This tests the baseline discrepancy; it does not add a generated column to the live table.

The original disposable copy above had already been dropped. Created a new **ordinary logged table** (not temporary or unlogged), `public.librarian_tsvector_probe_20261116`, at **2026-11-16 13:36:46 UTC**, holding **27,288 chunks**. Added a primary key and the same stored generated `search_tsv`, then analyzed the copy. This snapshot is intentionally retained for more experiments and survives normal PostgreSQL recovery. It is not registered as an application generation. Do not refresh or drop it without owner approval.

The live table was not altered, analyzed, vacuumed or reindexed. Its schema and indexes remained unchanged, and both tables held 27,288 rows before/after the comparison. The fixed embedding was verified identical between them; every stored text vector matched its on-demand expression.

Each input had a warm-up of all three variants followed by **five interleaved runs**, rotating which variant ran first. All used the same upstream `999d5fbe` SQL shape, vector, query words, weights, projection and limit. Repeated measurements used `TIMING OFF`; additional `TIMING ON` executions confirmed the same pattern.

| Search | Live, on demand | Frozen, on demand | Frozen, stored |
|---|---:|---:|---:|
| `CodeRankEmbed` | 405.668 ms | 280.789 ms | 127.577 ms |
| `project` | 404.571 ms | 284.602 ms | 129.912 ms |
| Full OpenCode question | 418.054 ms | 294.803 ms | 138.928 ms |

**The live baseline still takes 400+ ms.** The lower frozen baseline is reproducible during the same experiment; it is not evidence of an upstream query change or that production became faster overnight. The frozen stored-column improvement repeats at about **2.1–2.2×** in this run. Do not treat live-to-frozen differences as a stored-column speedup.

Both on-demand variants used parallel sequential scans and gather merge; stored queries used a nonparallel sequential scan. All variants had shared-buffer reads. The live and frozen columns used the same PostgreSQL storage settings and embedding type (`vector(768)`); their physical tables and statistics differ. The live table's statistics estimated 27,164 rows, versus 27,288 actual; the freshly analyzed copy estimated 27,288. These observations do not isolate the root cause of the roughly 120 ms live/copy gap. In particular, total relation size is not a measure of scanned data because it includes unused indexes.

The retained copy occupies **145,391,616 bytes** (about **138.66 MiB**) including its primary key and TOAST storage. It has no GIN or HNSW index. Its name, creation time, column/index layout and exact SQL used are recorded in the ignored local report `var/qa/live-frozen-tsvector-probe/result.json`; the driver is `/tmp/librarian-live-frozen-tsvector-probe.py`. Only the frozen copy was written. The live generation's schema and the application deployment remain unchanged.

## Owner-authorized live fork installation and measurement

The owner subsequently requested installing the actual Librarian fork with GIN and stored text vectors on the live tables, rather than stopping at SQL/copy experiments. This section supersedes the earlier not-deployed status.

### Installed package and schema

- Split fork: [ineersa/ai-postgres-store, `perf/stored-tsvector`](https://github.com/ineersa/ai-postgres-store/tree/perf/stored-tsvector), commit **`dabcaadbc326b460db948ba545e53f10aeb5d79d`**, tag **`v0.15.0.2`**.
- Librarian's Composer constraint is exactly `0.15.0.2`, with that commit locked. The initial development-branch commit-ref constraint triggered Composer's strict-validation warning; the reviewed tag resolved it without weakening QA or global stability.
- Preserves the existing 0.15 `Distance`/setup API, mandatory lexical gate, non-`COALESCE` null semantics, configured language, weighted scores and pre-limit `where`/`params`. **This is not a switch to current-main all-row mixed ranking.**
- Ran the package's actual `Store::setup()` in the verified production PHP runtime, first from the GIN backport, then from the exact stored-vector class installed by Composer. A bounded one-off PHP driver was used because Castor has no task to invoke this dynamically constructed store's setup directly. No vendor file was edited, and no deployment command/framework was added to the application.
- Upgraded both registered generation tables, `librarian_p1_g1` and `librarian_p2_g2`, before application deployment. Each setup ran in its own transaction with a 5-second lock timeout and 60-second statement timeout. Stored-column setup took about **14.06 seconds** and **1.69 seconds** respectively. Unlike the copied table, these live tables also had HNSW indexes during the rewrite. Do not infer the earlier 1.34-second copied-table DDL time applies to production.
- Deployed Librarian integration commit **`2dc9382`** through Castor `prod:config`, `prod:up`, `prod:restart`, `prod:ps` on **8093**. Verified the installed Composer reference in web and Messenger; the benchmark loaded `/app/vendor/symfony/ai-postgres-store/Store.php`, whose SHA-256 matched the reviewed fork.
- The live agent-core table now retains **primary key, HNSW and `librarian_p1_g1_search_tsv_gin`**. The corresponding project-2 stored-column GIN is valid too. Removed only the superseded expression GIN indexes created during this measurement, after verifying their replacements. **The stored-column GIN indexes remain installed.**

### Actual live `Store::query()` timings

These measurements invoke the PHP package, not a copied query alone. Each value is the median of five runs after a warm-up, with the same fixed existing vector, OR terms, equal weights and limit of 100. They include PDO fetch and document hydration, but exclude query embedding generation, application reranking and HTTP transport. All three stages used the same live agent-core table with **27,288 rows**. The database stayed live; stage changes were sequential, not an isolated load benchmark.

| Input | Original installed fork, no GIN | Expression GIN added | Deployed stored column + GIN |
|---|---:|---:|---:|
| `CodeRankEmbed` | 352.045 ms | 1.356 ms | **1.028 ms** |
| `project` | 369.835 ms | 37.800 ms | **11.069 ms** |
| Full OpenCode question | 451.433 ms | 169.809 ms | **25.441 ms** |

The first input returned 10 documents; the others returned 100. The original 0.15 keyword gate is unchanged. These improvements cannot be attributed to changing retrieval semantics. The generated-column installation rewrites storage, so the entire before/after reduction is not a pure CPU attribution to cached tokenization.

Actual fork-shaped EXPLAIN plans used the retained `search_tsv_gin` index for all three inputs. Every stored vector matched the original non-`COALESCE` expression across the live table. All-row text-rank comparisons and deterministic top-100 ID/score comparisons reported zero differences for every input.

### Current-main all-row SQL, on the same live table after upgrade

As a separate diagnostic, repeated current-main **ungated** mixed SQL on the upgraded live table, alternating the on-demand and stored expressions five times after warm-ups:

| Input | Live on-demand expression | Live stored expression |
|---|---:|---:|
| `CodeRankEmbed` | 296.028 ms | 134.080 ms |
| `project` | 291.183 ms | 131.724 ms |
| Full OpenCode question | 301.853 ms | 146.380 ms |

These are PostgreSQL EXPLAIN execution times, not PHP-store timings. Text was non-null for every current live row, so the fork's non-`COALESCE` stored vectors also match upstream's `COALESCE` expression for this dataset. This diagnostic does not alter deployed query semantics.

After adding/backfilling the column, even the unchanged on-demand expression became faster than the earlier 405–418 ms live baseline. This narrows the discrepancy to changes associated with the live schema/storage rewrite and execution conditions, not a different SQL version; it does not isolate one physical cause. The same-table stored-versus-on-demand comparison remains the defensible estimate for exhaustive mixed ranking.

### Validation and retention

- Fork package: **43 tests / 220 assertions**, CS Fixer clean, PHPStan passed.
- Librarian: `cs:fix` passed; saved Projects and MCP browser journeys passed (**2 journeys / 82 assertions**); `qa:static` passed, including Composer validation, style, PHPStan and LSP. Browser journeys exercised real package-backed indexing/search on isolated storage. The final tag points to the exact fork source tested by these journeys.
- Production `memory_search` through the existing anonymous MCP endpoint returned **HTTP 200, `ok: true`, five results** for the owner question. Web/PostgreSQL were healthy and Messenger running. Two initial verification requests omitted required modern-protocol headers and were rejected; the correctly formed request succeeded without changing application code or authentication.
- Frozen table **`public.librarian_tsvector_probe_20261116` remains retained**, unchanged at **27,288 rows**. No source OM database, journal or settings were modified, and no embeddings were rebuilt.
- Sanitized reports: ignored `var/qa/live-store-fork/{before,gin-only,stored-setup,after-deployed,sql-verification,mcp-verification}.json`. No source content or query vectors are committed.
- No Symfony AI PR was opened or updated. Both review branches and the Librarian dependency/documentation changes are pushed.
