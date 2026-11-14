# Plan 04 — indexing design

Status: official Symfony AI **Postgres** store on shared PostgreSQL + pgvector, active in the actual development application and browser stack. Checks and remaining gaps: [indexing validation](indexing-validation.md). MCP memory tools remain plan 05.

## Goal

Reuse Symfony AI facilities and keep Librarian-owned state in one database:

- Doctrine registration / generation / parent metadata
- Generation-scoped store tables created by the official Postgres bridge

Chunks, manifests and checkpoints share the same PostgreSQL transaction.

## Store and retrieval (as provided)

Locked dependency: Symfony AI Store + official Postgres bridge **0.15.0** with PostgreSQL + **pgvector**. Runtime image: `pgvector/pgvector:pg17-bookworm` (digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`). PHP extensions include `pdo_pgsql` and `pdo_sqlite`. Use the stock bridge as shipped.

- One store table per project/generation (isolation by table name, not metadata filters)
- Store document IDs are deterministic **UUIDv5** values derived from `[generation ID, kind, sourceId, full parent signature, chunk position]`. Format version **3** participates in the generation signature. This is the only approved UUID exception; application entities keep auto-increment integers
- `HybridQuery` uses weighted pgvector similarity + PostgreSQL `ts_rank` and requires a lexical match. The application passes whitespace-separated question terms as the vendor's OR-text array, not one text that would AND every significant word. The full question still drives embeddings and BGE reranking
- Native hybrid does **not** apply metadata/date filters; admin Search memory has no date-window UI
- Ranking is stock weighted hybrid, not BM25 and not RRF. Behavior preservation vs earlier backends is out of scope; testing/tuning comes later

Embeddings use the official Generic platform for the local OpenAI-compatible CodeRank endpoint and Symfony's native `Vectorizer`, with a small `ResultConvertedEvent` listener for dimension/cardinality/finite-vector checks. Query prefix remains `Represent this query for searching relevant code: `. Configured baseline remains **768** dimensions.

Local BGE keeps the small Voyage request/response adapter for llama.cpp `/v1/rerank` (`results[{index,relevance_score}]`). No hosted Voyage backend and no custom HTTP transport.

## Corpus and identity

Read retained `om_observation` and `om_reflection` only. Journals are not embedded.

- Observation: `observation_id`, `run_id`, `content`, `timestamp`, `relevance`
- Reflection: `reflection_id`, `run_id`, `content`, `created_at`

Reuse discovery's schema/version validation and read-only PDO opener. Indexing does not migrate source schemas.

Chunker stays UTF-8 byte-based: 1,200 bytes, overlap 192, at most 80 lines; embedding batches ≤4. Different parent versions must not collide across chunk IDs.

Application entities:

- `IndexGeneration`: project relation, signature, source identity/path/root binding, active flag, state, scan cursor/round, committed revision, parent/chunk counts, timestamps, sanitized error
- `IndexedMemory`: generation relation, canonical kind/ID, content/metadata signature, chunk ID list, last-seen scan round

PostgreSQL migrations are generated into `migrations/postgres` under namespace `DoctrineMigrations\Postgres`. Do not hand-author migration classes or schema SQL. See [runtime validation](indexing-validation.md#development-runtime).

## Source changes

OM schema 006 revision/change rows are a cheap hint, not a durable independent journal. Librarian never consumes those rows.

Canonical change signal is ordinary metadata revision advance:

1. Acquire the Symfony project lock
2. Verify binding / source identity / embedding signature; select or create the generation
3. Read one bounded canonical batch under a short read-only source transaction, then close it before model or store work
4. Compare parent signatures. Unchanged parents only mark the scan round seen
5. Embed outside the short write transaction. Upsert/remove store chunks and commit the relational manifest together in one short PostgreSQL transaction
6. Advance the cursor only after that work succeeds. After traversal, remove unseen parents in bounded batches and publish the committed boundary

Generation registration and native store provisioning commit together once. Ordinary indexing batches open the existing store without repeating extension/table/index setup. Source pages omit the range predicate initially, then use a direct primary-key range for subsequent pages. Transaction failures use Doctrine's registry reset before recording sanitized Error state; deferred obsolete cleanup also resets a closed manager so synchronous draining can continue.

Source identity remains path + filesystem device/inode. A changed filesystem identity or lower revision starts a fresh generation. An in-place restore that preserves identity and revision is not reliably detected.

## Scheduling

Hatfield does not notify Librarian. Symfony Scheduler keeps indexes fresh inside the existing Messenger worker — not a separate OS cron.

Default interval is `INDEX_INTERVAL=60` seconds. `CallbackMessageProvider` yields existing source-discovery messages for registered projects; successful discovery queues incremental indexing. This refreshes paths, session/source counts and schema metadata as well as detecting memory changes. Both discovery and indexing use queue-only Messenger deduplication; the project execution lock protects index writes. No custom tick message, pagination coordinator, or polling daemon. Bound each source batch, embedding batch, and HTTP request.

Admin list/detail status updates arrive through Mercure + Turbo Streams after committed checkpoints, without document refreshes. Source discovery and indexed-memory counts are separate. Discovery/indexing are automatic; there are no manual scan/index buttons. Projects supports registration/removal, row-click details and per-row Search memory. Search remains part of the existing Projects journey. MCP memory tools remain plan 05.

An unchanged revision skips embedding/store reconciliation but still touches the generation activity time and publishes status after the short write. The UI shows last source/index check separately from last successful indexing of a new revision. An old successful-index timestamp does not mean automatic checks stopped.

## Ranking shape

Stock Postgres `HybridQuery` candidates (OR across question terms) → BGE on raw question/chunks (batches 8, 768 Unicode characters, floor -4) → stable order/ties → parent collapse. Report truncation and stale/partial status explicitly. Do not treat hybrid score as calibrated confidence.

## Documentation consulted

- https://symfony.com/doc/current/ai/components/store.html
- https://symfony.com/doc/current/ai/components/platform.html
- https://symfony.com/doc/current/scheduler.html
- https://symfony.com/doc/current/lock.html
- https://symfony.com/doc/current/messenger.html
- Official Postgres bridge source (`Symfony\AI\Store\Bridge\Postgres\Store`) for HybridQuery / UUID schema behavior
