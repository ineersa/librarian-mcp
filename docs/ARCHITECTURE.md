# Librarian MCP — Architecture (current)

## 1) System shape

The app is a Symfony web/admin application that:

1. Stores a catalog of libraries (GitHub repo + branch + metadata)
2. Asynchronously clones and indexes each library with Vera
3. Exposes indexed libraries through MCP tools

Core runtime pieces:

- **Web/admin:** Symfony + EasyAdmin
- **Persistence:** Doctrine ORM + SQLite
- **Async pipeline:** Symfony Messenger (`async` transport)
- **Realtime status UI:** Mercure + Stimulus controller
- **Search/index engine:** Vera CLI (invoked by Symfony `Process`)

---

## 2) Domain model

## `Library` (`src/Entity/Library.php`)

Main fields:

- `id` (int auto-increment)
- `name`
- `slug` (unique)
- `gitUrl` (GitHub HTTPS, validator-enforced)
- `branch` (default `main`)
- `path` (unique, immutable after creation)
- `description`
- `status` (`draft|queued|indexing|ready|failed`)
- `veraConfig` (JSON-backed `VeraIndexingConfig` value object)
- `readableFiles` (JSON map `relative/path => true` used by MCP read sandbox)
- `lastError`, `lastSyncedAt`, `lastIndexedAt`, `createdAt`, `updatedAt`

Status transitions are guarded by domain methods:

- `markQueued()`
- `syncStarted()`
- `syncFailed()`
- `syncSucceeded()`

Invalid transitions throw `LogicException`.

---

## 3) Service boundaries

## `LibraryManager` (`src/Service/LibraryManager.php`)

Owns library business logic and filesystem orchestration:

- derive defaults (`deriveName`, `generateSlug`, `computePath`)
- enforce uniqueness (`slug`, `path`)
- resolve absolute path: `<project>/<libraryDataDir>/libraries/<path>`
- CRUD-side orchestration (`create`, `update`, `delete`)
- queue sync (`markQueued`) by dispatching `SyncLibraryMessage`
- clone directory preparation (`prepareCloneDirectory`)
- metadata corpus updates (`LibraryMetadataCorpus` integration)

Nuance:
- `create()` persists first, updates metadata corpus, then auto-queues indexing (`markQueued()`).

## `VeraCli` (`src/Vera/VeraCli.php`)

Pure CLI wrapper (no entity knowledge):

- `cloneRepository()`
- `indexLibrary()`
- `searchLibrary()`
- `grepLibrary()`

It logs command start/end/failure, applies timeouts, and turns command/process failures into `VeraCliException`.

---

## 4) Async sync/index pipeline

## Message

- `SyncLibraryMessage` carries `libraryId`.
- Routed to Messenger `async` transport.

## Handler (`src/MessageHandler/SyncLibraryMessageHandler.php`)

Pipeline (happy path):

1. Load library
2. Guard: skip if status is not `queued` (concurrency/race protection)
3. `queued -> indexing`, flush, publish Mercure status
4. Prepare clone directory (delete old tree if present)
5. `git clone --branch`
6. `vera index`
7. Build `readableFiles` manifest from detected text files
8. `indexing -> ready`, flush
9. Update metadata corpus
10. Publish Mercure status

Failure path:

- `syncFailed(error)` + flush
- metadata corpus update (removes/non-ready handling)
- publish failed status
- throw `UnrecoverableMessageHandlingException` (manual retry model)

Nuances:

- Manifest builder uses `finfo` MIME checks to include text-like files only.
- Mercure topic is fixed to `https://librarian-mcp.local/topics/libraries`.
- Handler is intentionally linear in one method for observability and simplicity.

---

## 5) Metadata discovery corpus

## `LibraryMetadataCorpus` (`src/Mcp/LibraryMetadataCorpus.php`)

Maintains a small side corpus under:

- `<project>/<libraryDataDir>/mcp-metadata-corpus`

For `ready` libraries, writes one `slug.txt` document containing:

- slug/name/git URL/branch/last indexed timestamp
- description text

After upsert/remove it reindexes this corpus via Vera (`vera index`).
This powers semantic augmentation for library discovery (`search-libraries`).

---

## 6) Admin + UX integration

- EasyAdmin dashboard + CRUD controllers for users/libraries
- Sync is triggerable from admin (`Sync now`) and auto-triggered on create
- Library list status updates in real time via Mercure + Stimulus

---

## 7) Storage layout

Under configured `libraryDataDir` (default `data`):

- `data/libraries/<owner>/<repo>/<branch>` — cloned repository + Vera index state
- `data/mcp-metadata-corpus` — metadata docs for cross-library discovery

---

## 8) Operational model

- Long-running work is async (Messenger worker required)
- Failures are terminal for a message (no automatic retry); admin can re-queue
- MCP surface is stable and data-driven (adding libraries does not add/remove MCP tool definitions)
