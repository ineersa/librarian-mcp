# Plan 04 — indexing design

Status: owner-approved simplification to the official Symfony AI **Postgres** store. Application code/config cutover is owned by main; completed checks and remaining gaps live in [indexing validation](indexing-validation.md). Production cutover and MCP memory tools remain plans 06 and 05.

## Goal

Reuse Symfony AI facilities and keep Librarian-owned state in one database:

- Doctrine registration / generation / parent metadata
- Generation-scoped store tables created by the official Postgres bridge

No private Chroma service, no separate SQLite FTS files, no CombinedStore/RRF adapters, and no cross-store pending protocol.

## Store and retrieval (as provided)

Locked target: Symfony AI Store + official Postgres bridge **~0.15** with PostgreSQL + **pgvector**. Use the stock bridge as shipped.

- One store table per project/generation (isolation by table name, not metadata filters)
- Document IDs follow the bridge schema (**UUID**). This is the only approved UUID exception; application entities keep auto-increment integers
- `HybridQuery` uses weighted pgvector similarity + PostgreSQL `ts_rank` and requires a lexical match
- Native hybrid does **not** apply metadata/date filters; admin Search memory has no date-window UI
- Ranking is stock weighted hybrid, not BM25 and not RRF. Behavior preservation vs the earlier Chroma path is explicitly out of scope; testing/tuning comes later

Embeddings use the official Generic platform for the local OpenAI-compatible CodeRank endpoint and Symfony's native `Vectorizer`, with a small `ResultConvertedEvent` listener for dimension/cardinality/finite-vector checks. Query prefix remains `Represent this query for searching relevant code: `. Configured baseline remains **768** dimensions.

Local BGE keeps the small Voyage request/response adapter for llama.cpp `/v1/rerank` (`results[{index,relevance_score}]`). No hosted Voyage backend and no custom HTTP transport.

## Corpus and identity

Read retained `om_observation` and `om_reflection` only. Journals are not embedded.

- Observation: `observation_id`, `run_id`, `content`, `timestamp`, `relevance`
- Reflection: `reflection_id`, `run_id`, `content`, `created_at`

Reuse discovery's schema/version validation and read-only PDO opener. Indexing does not migrate source schemas.

Chunker stays UTF-8 byte-based: 1,200 bytes, overlap 192, at most 80 lines; embedding batches ≤4. Chunk identity is deterministic SHA-256 business content used to derive the store UUID; different parent versions must not collide.

Application entities:

- `IndexGeneration`: project relation, signature, source identity/path/root binding, active flag, state, scan cursor/round, committed revision, parent/chunk counts, timestamps, sanitized error
- `IndexedMemory`: generation relation, canonical kind/ID, content/metadata signature, chunk ID list, last-seen scan round

Legacy SQLite migration history is retained. New PostgreSQL baseline migrations are generated into `migrations/postgres` under namespace `DoctrineMigrations\Postgres`. Do not hand-author SQL.

## Source changes

OM schema 006 revision/change rows are a cheap hint, not a durable independent journal. Librarian never consumes those rows.

Canonical change signal is ordinary metadata revision advance:

1. Acquire the Symfony project lock
2. Verify binding / source identity / embedding signature; select or create the generation
3. Read one bounded canonical batch under a short read-only source transaction, then close it before model or store work
4. Compare parent signatures. Unchanged parents only mark the scan round seen
5. Embed outside the short write transaction. Upsert/remove store chunks and commit the relational manifest together in one short PostgreSQL transaction
6. Advance the cursor only after that work succeeds. After traversal, remove unseen parents in bounded batches and publish the committed boundary

Source identity remains path + filesystem device/inode. Unused DB/WAL witness hashing and unused snapshot count fields are deleted. A changed filesystem identity or lower revision starts a fresh generation. An in-place restore that preserves identity and revision is not reliably detected.

## Scheduling

Hatfield does not notify Librarian. Symfony Scheduler keeps indexes fresh inside the existing Messenger worker — not a separate OS cron.

Default interval is `INDEX_INTERVAL=60` seconds. Use `CallbackMessageProvider` so each tick yields project indexing jobs directly. No custom tick message, pagination coordinator, or polling daemon. Queue-only Messenger deduplication plus the project execution lock remain the admission/execution barriers. Bound each source batch, embedding batch, and HTTP request.

Admin exposes index status, Index now, and Search memory in the existing Projects journey. MCP memory tools remain plan 05.

## Ranking shape

Stock Postgres `HybridQuery` candidates → BGE on raw question/chunks (batches 8, 768 Unicode characters, floor -4) → stable order/ties → parent collapse. Report truncation and stale/partial status explicitly. Do not treat hybrid score as calibrated confidence.

## Documentation consulted

- https://symfony.com/doc/current/ai/components/store.html
- https://symfony.com/doc/current/ai/components/platform.html
- https://symfony.com/doc/current/scheduler.html
- https://symfony.com/doc/current/lock.html
- https://symfony.com/doc/current/messenger.html
- Official Postgres bridge source (`Symfony\AI\Store\Bridge\Postgres\Store`) for HybridQuery / UUID schema behavior
