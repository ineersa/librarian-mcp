# Plan 04 — implementation design

Status: implementation design, not acceptance evidence. The model/backend choice is approved by plan 02; the concrete integration below still requires runtime verification. Production cutover and memory MCP tools remain plans 06 and 05 respectively.

## Versioned integration and framework reuse

Candidate dependency set: Symfony AI Store, Generic/Voyage platform components, ChromaDB bridge and SQLite bridge **0.15.0**, PHP Chroma client **1.0.0**, Chroma server **1.0.21**. Keep the existing Symfony 8.2 prerelease constraints and global stable stability policy. Add Symfony Lock and Scheduler from the same approved 8.2 minor.

The 0.15 Chroma bridge requires PHP client `^1.0`; that client's tagged README requires Chroma >=1.0 and uses the v2 API. The bridge's test Compose still names 0.6.3: this is not a compatible-version proof. Verify the proposed 1.0.21 combination against an isolated service before claiming compatibility.

Use the official PHP client to pre-create/verify a collection with `hnsw:space=cosine`; the bridge's `setup()` cannot set distance options. Pass explicit vectors to the bridge's idempotent `add()` (which calls Chroma `upsert()`); do not configure a Chroma embedding function. For query results explicitly include documents, embeddings, metadatas and distances. Native metadata `where` filters and `limit=100` belong to the Chroma branch. No custom Chroma HTTP transport or vector distance implementation.

Symfony AI 0.15 `VectorDocument` accepts integer/string IDs; UUID/ULID fields are not needed. `CombinedStore(vectorStore, textStore, rrfK: 60)` supplies the existing RRF implementation. It forwards the same option array to both branches, so a small adapter must translate shared search options to each backend's supported options.

Symfony's SQLite bridge supplies FTS5 schema creation, writes, deletes and native BM25 ranking. Its stock text query filters metadata **after** its SQL LIMIT. For correct bounded project/date retrieval, the lexical adapter must apply those predicates in SQL before LIMIT against the vendor-owned tables. Reuse SQLite FTS5 rank, not a PHP BM25 implementation. Never invoke the SQLite vector/exact-distance query path. Store only a one-dimensional placeholder in its lexical document records, not duplicate embedding arrays; the reference Hatfield adapter uses the same lexical-only representation.

Use the official Generic platform for the OpenAI-compatible CodeRank endpoint and Symfony AI's document/vectorization pipeline, preserving raw document text. Its factory supports configurable embedding paths; `/v1` base URLs need `/embeddings`, not another `/v1/embeddings` suffix. Query preprocessing remains `Represent this query for searching relevant code: ` plus the raw query. Validate exact configured dimensions (baseline 768), response cardinality and finite nonzero vectors. Provider bodies and source text must not enter logs or exception chains; disable HTTP response-body tracing and leave vectorizer content logging on a null logger.

The existing BGE contract is llama.cpp `/v1/rerank`, `results[{index,relevance_score}]`. The installed 0.15 Voyage `Reranker\\ModelClient` supports a configured base URL and sends the required `model/query/documents` body to `/v1/rerank`. Reuse that official request client against the local endpoint with no truncating top-k option; verify that the endpoint returns every input index. Its stock Voyage result converter expects `data`, not llama.cpp's `results`, so retain strict application-side result count/type/index/finite-score validation. This selects no hosted Voyage backend and adds no custom HTTP transport. Preserve sanitized visible failure, not an embeddings-only fallback.

## Corpus and identity

Read retained `om_observation` and `om_reflection` only. Canonical projection:

- observation: `observation_id`, `run_id`, `content`, `timestamp`, `relevance`;
- reflection: `reflection_id`, `run_id`, `content`, `created_at` (no synthetic importance).

Keep the full canonical ID and session/run ID. Normalize dates to UTC epoch seconds for native numeric filters in both branches; retain canonical date/provenance for recall. Journals are not embedded.

Preserve the existing UTF-8 chunker: 1,200 bytes, overlap 192 bytes, at most 80 lines; embedding batches at most four. Do not replace byte boundaries with character or token boundaries during the storage comparison. Chunk keys are deterministic SHA-256 business identifiers over project ID, canonical kind/ID, generation signature, position and chunk content, not UUIDs or database primary keys.

All application-owned entities use generated integer primary keys. Proposed minimal entities:

- `IndexGeneration`: project relation, signature, source identity/path, active flag, state, durable scan cursor/round and committed source revision, counts, timestamps and sanitized error;
- `IndexedMemory`: generation relation, canonical kind/ID, session/date, content/metadata signature, bounded-per-parent chunk key list and last-seen scan round.

The SQLite bridge's derived document/FTS tables are vendor-managed index structures in a separate Librarian-owned SQLite file. Keep them outside application ORM schema ownership. Relational registrations/progress are migrated with Doctrine's generator against isolated storage. Do not hand-author migrations or virtual-table migration SQL.

## Collections and generations

Use a separate collection per project and generated generation ID, e.g. `librarian_p<project-id>_g<generation-id>`. The embedding signature covers exact artifact identity, dimensions, document preprocessing, normalization and chunking settings. Never reuse a collection for an incompatible signature. Require explicit artifact identity rather than silently equating a mutable model alias with an immutable artifact.

Source identity includes canonical database path and filesystem identity; root rebinding, source replacement, revision rollback or embedding-signature change starts a separate generation. Keep the previous active generation usable during a replacement build. Publish the new active pointer only after complete acknowledged vector/lexical work, with a short relational transaction that rechecks the current project binding. Retired generations and registration deletion may remove only Librarian-owned collections/rows/files.

A vector query is constrained natively by project, generation and optional inclusive/exclusive normalized date bounds. The lexical query uses the same bounds before LIMIT. Never compensate with corpus-sized PHP overfetch. Hydrate only committed relational parent references; a vector hit alone does not establish recall provenance.

## Source changes and checkpoint ownership

OM schema 006 provides a global revision and coalesced `(kind,source_id,revision)` changes. **This is not a durable independent-consumer journal**: the existing Hatfield semantic reconciler deletes consumed change rows. Librarian must never consume/acknowledge those rows or assume no visible changes means no canonical changes.

The safe initial reconciliation is bounded canonical keyset traversal when source state advances, comparing parent signatures and embedding only changed content. Use the revision/change information as a cheap signal/hint, not proof of a complete independent change feed. An unchanged populated source skips traversal and embedding; searches never ingest. Record the changed-source reconciliation cost honestly, including its manifest traversal cost. Do not introduce a second change-feed coordinator to hide the source contract limitation.

Each work item:

1. Acquire the existing Symfony framework project lock; one operation owns its checkpoint. Lock files must be shared by web/worker/tooling in this checkout's owned storage.
2. Verify project binding, source identity and signature; select/create the target generation and capture its revision boundary.
3. Read one consistent bounded canonical batch under a read-only source transaction, then close that transaction before model or Chroma calls.
4. Compare signatures. For changed parents, vectorize in batches <=4, write deterministic chunks to Chroma and the lexical store, and remove obsolete chunk IDs idempotently. A retry replays the same IDs.
5. Only after both backends acknowledge the corresponding work, commit relational parent metadata and the batch cursor in a short transaction. No SQLite transaction is claimed to cover Chroma.
6. After traversal, remove unseen parents/chunks in bounded batches, then commit the source boundary. If the source advanced meanwhile, enqueue another round; do not claim the newer source fully indexed. Check root binding again before publishing results.

Failure leaves the checkpoint behind the failed work, so Messenger's existing retry policy replays acknowledged/partial deterministic writes safely. Do not add a custom retry service. Surface stale/updating/error state; no atomic multi-store visibility claim. Chroma/SQLite do not offer a shared transaction, so incremental search may temporarily lose candidates while an update is in flight; committed parent references must never resolve to another project/source.

Source restore detection needs explicit verification: replacement filesystem identity and lower revisions are clear reset signals; an in-place restore with an identical revision cannot be distinguished by revision alone. Verify available bounded file/change witnesses and document remaining limitations before acceptance. Do not silently claim all restores are detected or perform full corpus verification on every unchanged poll.

## Scheduling and bounded work

Use Symfony Scheduler to periodically dispatch project indexing work at a configurable interval, consumed through Messenger. Use framework deduplication for queue admission and the project lock for execution. Queue-only deduplication is needed when a batch enqueues its continuation: holding the deduplication lock until handler exit would otherwise suppress that continuation. Bound each source/cleanup batch, embedding batch and HTTP request duration. Do not retain a full corpus/vector cache in PHP or introduce a custom polling daemon.

Expose index status, explicit index action and search preview in the existing project administration; memory MCP tools remain plan 05. Extend the existing Projects happy-path journey rather than adding discovery/error/concurrency matrices or another test suite.

## Ranking baseline

Vector100 + lexical100 -> Symfony RRF k60 -> at most 200 fused chunks -> BGE on raw question/chunks in batches8, truncating each chunk to 768 Unicode characters -> floor -4 -> stable chunk order/ties -> parent collapse/deduplication. Do not threshold RRF as confidence. Report candidate-limit truncation and stale/partial index status explicitly.

## Evidence required before acceptance

Runtime compatibility and cosine/create/upsert/update/delete/filter/result-budget evidence; existing happy-path browser/QA gate; repeated full/incremental/unchanged populated-corpus build/search measurements; PHP live/allocated/peak and settled PHP/Chroma process memory; final parent/chunk counts; frozen50 plus five-identifier comparison on the same consistent corpus. Keep private questions, source snapshots, texts, vectors and raw provider responses out of Git and logs. Record process counts, model memory separately, and known validation gaps. Special-case fault/concurrency tests still require owner permission.

## Sources inspected

- Symfony AI Store docs: https://symfony.com/doc/current/ai/components/store.html
- Symfony AI platform docs: https://symfony.com/doc/current/ai/components/platform.html
- Symfony Scheduler/Lock/Messenger docs: https://symfony.com/doc/current/scheduler.html , https://symfony.com/doc/current/lock.html , https://symfony.com/doc/current/messenger.html
- Tagged 0.15 Store/Generic/Chroma/SQLite package source and Composer requirements; client 1.0.0 source/README. Current docs alone are not proof of tagged APIs.
- Hatfield source schema migration 006, `SemanticIndexService`, `SemanticApiClient`, `MemoryChunker`, `MemoryStoreAdapter` and `SemanticSettings`, inspected read-only in the local checkout. No private runtime settings or source payloads are copied into this document.
