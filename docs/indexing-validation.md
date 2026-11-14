# Indexing validation

Validated on 2026-11-13 after switching to the unmodified Symfony AI Postgres store **0.15.0**, PostgreSQL **17** + pgvector, and a shared Doctrine/store connection. See [indexing-design.md](indexing-design.md).

## Checks performed

- `vendor/bin/castor tooling:prepare` passed with `pdo_pgsql`; `pdo_sqlite` remains available for read-only Hatfield sources.
- Generated `migrations/postgres/Version20261113171951.php` through Doctrine against task-owned `librarian_iso_pg_native_schema`. Reviewed its forward changes: application/Messenger tables, integer identity keys, indexes and foreign keys; no existing tables dropped. Applied it there and `doctrine:schema:validate` confirmed the mappings and database agree.
- `vendor/bin/castor cs:fix` and `qa:static` passed: Composer validation, style, PHPStan and runtime-complete Symfony LSP checks.
- Latest production-usability follow-up: `vendor/bin/castor cs:fix`, `test` and `qa:static` passed. **5 headless browser journeys, 48 assertions**, zero failures/errors. Exactly one Projects journey covers registration, row-click details, live automatic source/index status, per-row natural-language native hybrid search + BGE, an ordinary synthetic source update picked up by scheduled discovery/indexing, updated search and removal with source-hash checks. Edit and manual scan/index actions are removed. No scenario matrix was added.
- A throwaway in-memory SQLite `EXPLAIN QUERY PLAN` confirmed subsequent source pages use primary-key `SEARCH … (observation_id>?)`; the first page scans in primary-key order. This is query-plan evidence, not a corpus benchmark.
- `vendor/bin/castor dev:stack:up` passed after removing development Compose model overrides, recreating the running development services with the corrected environment configuration.

## Development runtime

The development application and browser stack both run PostgreSQL with the Doctrine-generated baseline. Use `vendor/bin/castor dev:stack:status` for the current shared-proxy URL; see [Castor operations](castor.md#isolated-browser-stack). Schema validation passed. No migration classes or schema SQL were handwritten.

A headless browser verified the actual development homepage → native EasyAdmin login: both HTTP 200, assets loaded, no console errors. This check did not submit credentials. The existing browser journey remains the evidence for authenticated indexing/search behavior.

## Initial production usability deployment

PR #4 code at `e184c54` was deployed to the existing production stack through Castor `prod:up` and `prod:restart`, without merging the PR. `prod:config` passed. Web/PostgreSQL were healthy; Messenger was running with its inherited HTTP health check disabled. All services used `unless-stopped`; both app containers retained the identical read-only projects mount. No migration, database reset, source mutation or volume removal was performed.

A headless production browser verified:

- Projects list showed Indexed, 26,606 indexed memories at that observation, last success and automatic-update guidance; detail showed separate live source/index panels and normal admin controls, with no meta refresh.
- The project-specific Mercure subscription returned HTTP 200 `text/event-stream`, with EventSource OPEN. During 70.7 seconds the document did not reload, but no status event arrived: actual production panel replacement remains unproven in that idle observation window.
- The exact reported natural-language query at limit 10 returned **10 results**, versus two newer question-related records before deployment. Historical OpenCode provider/token-usage work was present, but ranking remained mixed: the first three results were recent question/search-related repetitions. No visible or console errors. Only that query was submitted; no indexing actions or record changes were made.

Private result snapshots remain local and are not part of this documentation.

## Automatic discovery follow-up

Code at `ac95fd9` was deployed through Castor `prod:up` and `prod:restart`. Source and committed revisions had both been 6946 when the owner reported the stale timestamp: unchanged data was already indexed, but full periodic discovery and visible check activity were missing.

The existing Projects browser journey now verifies scheduler-only discovery and indexing after its ordinary fixture update. Formatting, all **5 journeys / 48 assertions**, static QA and diff checks passed.

A production browser then observed an ordinary automatic cycle without manual scan/index actions, searches or agent-made source changes:

- Source-check time advanced from 02:29:15 to 02:30:15 UTC on 2026-11-14, with corresponding index activity; the page transitioned from Updating index to Indexed with 26,638 memories.
- Actual Mercure messages replaced both status panel DOM nodes. An in-memory marker and time origin survived; navigation count stayed at one. No meta refresh or console errors.
- The next check advanced both check timestamps to 02:31:15 while last successful index stayed at 02:29:53, proving an unchanged-data check is visible without claiming a new commit. The list showed the separate check time too.

Production automatic checks and live status replacement are therefore observed, not merely wired. No owner data was reset or source changes forced.

## Limits

- Native hybrid uses weighted pgvector similarity and PostgreSQL `ts_rank`, requires a lexical match, and has no date-filter UI. Relevance/performance tuning is deferred.
- The bridge creates no full-text index, and HNSW cannot satisfy hybrid's weighted ordering. The 100-candidate limit bounds returned results and reranking, not database scanning. Realistic-corpus search performance is unestablished; a matching GIN expression index is a future upstream improvement, not part of this change.
- Autonomous pickup is browser-proven with the existing synthetic fixture and the browser stack's short scheduler interval. No source changes were forced in production to test ingestion.
- No benchmarks, fault injection, concurrency or isolation matrix were added or run.
- Proxy-port conflicts and closed-manager failure paths were inspected, not exercised through new negative/fault scenarios. Happy-path checks do not establish those boundaries.
- In-place source restores preserving filesystem identity and canonical revision are not reliably detected.
