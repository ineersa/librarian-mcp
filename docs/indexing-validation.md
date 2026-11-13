# Indexing validation

Honest status for plan 04 after the owner-approved PostgreSQL simplification. Design: [indexing-design.md](indexing-design.md).

## Current claim

PostgreSQL + official Symfony AI Postgres store cutover validation is **pending main**. Do not treat earlier Chroma/SQLite measurements as proof of the new backend.

## Still required from main

- Dependency pin for `symfony/ai-postgres-store` ~0.15 and removal of Chroma/SQLite store packages
- Postgres 17 + pgvector service wiring (exact image/tag owned by the infrastructure child / main)
- Generated `migrations/postgres` baseline under `DoctrineMigrations\Postgres`, applied and schema-validated on isolated task-owned storage
- Scheduler simplified to `CallbackMessageProvider` yielding indexing jobs directly
- Search UI without date filters; isolation by generation-scoped table names
- `vendor/bin/castor cs:fix`, `qa:static`, and the existing five happy-path browser journeys (exactly one Projects journey)

## Historical Chroma path (not PG proof)

Before the Postgres switch, the Chroma + SQLite FTS path had:

- Static checks and five browser journeys / 43 assertions on that earlier stack
- One populated build at revision 6624 with 26,284 parents / 26,393 chunks
- Frozen50 cutoffs matching the original CodeRank/Vektor hybrid+BGE pipeline at 20/38 @5, 24/38 @10, 26/38 @20 on that historical backend

Those results are retained only as historical context. They do not validate Postgres hybrid/`ts_rank` behavior, UUID document IDs, or the shared Doctrine+store database.

## Known gaps (unchanged product limits)

- Populated Postgres incremental/performance measurements are not claimed
- Autonomous periodic pickup is not browser-proven (journey still requests scan/index after mutation)
- No fault-injection, concurrency, multi-project isolation, restore, or production-cutover matrix
- In-place restores that preserve filesystem identity and canonical revision are not reliably detected
- Stock hybrid requires lexical match and does not preserve prior RRF/BM25 ranking
