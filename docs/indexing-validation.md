# Indexing validation

Validated on 2026-11-13 after switching to the unmodified Symfony AI Postgres store **0.15.0**, PostgreSQL **17** + pgvector, and a shared Doctrine/store connection. See [indexing-design.md](indexing-design.md).

## Checks performed

- `vendor/bin/castor tooling:prepare` passed with `pdo_pgsql`; `pdo_sqlite` remains available for read-only Hatfield sources.
- Generated `migrations/postgres/Version20261113171951.php` through Doctrine against task-owned `librarian_iso_pg_native_schema`. Reviewed its forward changes: application/Messenger tables, integer identity keys, indexes and foreign keys; no existing tables dropped. Applied it there and `doctrine:schema:validate` confirmed the mappings and database agree. Historical SQLite migrations were not altered or executed against PostgreSQL.
- `vendor/bin/castor cs:fix` and `qa:static` passed: Composer validation, style, PHPStan and runtime-complete Symfony LSP checks.
- `vendor/bin/castor test` passed: **5 headless browser journeys, 43 assertions** (browser execution about 12.5 seconds). Exactly one Projects journey covers registration/discovery, Index now, native hybrid search + BGE, an ordinary synthetic source update and updated search, path rebinding, and removal with source-hash checks.

Only checkout-owned browser/diagnostic PostgreSQL databases were created or reset. Production services and owner databases were not started, migrated or reset. Existing SQLite files and old Chroma storage were not deleted; transferring owner data requires the later production-cutover work.

## Limits

- Native hybrid uses weighted pgvector similarity and PostgreSQL `ts_rank`, requires a lexical match, and has no date-filter UI. Prior Chroma/RRF/BM25 results are not evidence for this backend; relevance/performance tuning is deferred.
- The Scheduler callback is wired, but autonomous periodic pickup is not separately browser-proven: the existing journey explicitly requests indexing after its fixture update.
- No benchmarks, fault injection, concurrency, isolation matrix or production-cutover tests were added or run.
- In-place source restores preserving filesystem identity and canonical revision are not reliably detected.
