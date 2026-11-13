# Indexing validation

Validated on 2026-11-13 after switching to the unmodified Symfony AI Postgres store **0.15.0**, PostgreSQL **17** + pgvector, and a shared Doctrine/store connection. See [indexing-design.md](indexing-design.md).

## Checks performed

- `vendor/bin/castor tooling:prepare` passed with `pdo_pgsql`; `pdo_sqlite` remains available for read-only Hatfield sources.
- Generated `migrations/postgres/Version20261113171951.php` through Doctrine against task-owned `librarian_iso_pg_native_schema`. Reviewed its forward changes: application/Messenger tables, integer identity keys, indexes and foreign keys; no existing tables dropped. Applied it there and `doctrine:schema:validate` confirmed the mappings and database agree.
- `vendor/bin/castor cs:fix` and `qa:static` passed: Composer validation, style, PHPStan and runtime-complete Symfony LSP checks.
- Latest manual-review follow-up: `vendor/bin/castor cs:fix`, `test` and `qa:static` passed. **5 headless browser journeys, 48 assertions**, zero failures/errors (14.2 seconds browser execution; 34.2 seconds including stack/reset setup; static checks 5.5 seconds). Exactly one Projects journey covers registration/discovery, Index now, native hybrid search + BGE, an ordinary synthetic source update and updated search, path rebinding, and removal with source-hash checks. No saved scenarios or assertions were added by this follow-up.
- A throwaway in-memory SQLite `EXPLAIN QUERY PLAN` confirmed subsequent source pages use primary-key `SEARCH … (observation_id>?)`; the first page scans in primary-key order. This is query-plan evidence, not a corpus benchmark.
- `vendor/bin/castor dev:stack:up` passed after removing development Compose model overrides, recreating the running development services with the corrected environment configuration.

## Development runtime

The development application and browser stack both run PostgreSQL with the Doctrine-generated baseline. Use `vendor/bin/castor dev:stack:status` for the current shared-proxy URL; see [Castor operations](castor.md#isolated-browser-stack). Schema validation passed. No migration classes or schema SQL were handwritten.

A headless browser verified the actual development homepage → native EasyAdmin login: both HTTP 200, assets loaded, no console errors. This check did not submit credentials. The existing browser journey remains the evidence for authenticated indexing/search behavior.

## Limits

- Native hybrid uses weighted pgvector similarity and PostgreSQL `ts_rank`, requires a lexical match, and has no date-filter UI. Relevance/performance tuning is deferred.
- The bridge creates no full-text index, and HNSW cannot satisfy hybrid's weighted ordering. The 100-candidate limit bounds returned results and reranking, not database scanning. Realistic-corpus search performance is unestablished; a matching GIN expression index is a future upstream improvement, not part of this change.
- The Scheduler callback is wired, but autonomous periodic pickup is not separately browser-proven: the existing journey explicitly requests indexing after its fixture update.
- No benchmarks, fault injection, concurrency or isolation matrix were added or run.
- Proxy-port conflicts and closed-manager failure paths were inspected, not exercised through new negative/fault scenarios. Happy-path checks do not establish those boundaries.
- In-place source restores preserving filesystem identity and canonical revision are not reliably detected.
