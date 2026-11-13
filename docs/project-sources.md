# Local projects, Hatfield sources, and derived indexing

## Current product boundary

Librarian registers **local directories**. It discovers Hatfield OM/session metadata read-only, then builds Librarian-owned hybrid indexes from retained observations/reflections. It does not boot Hatfield, load extension PHP, run source migrations, or clone repositories.

- **Sources discovered** (`ready`) means discovery succeeded, not that memories are indexed.
- **Search memory** is an admin action over derived data. Indexing starts automatically after discovery and on the scheduler (default 60s). **Check for updates** is optional and never rebuilds. Search never synchronously ingests or rebuilds a project and has no date-window UI.
- Public MCP memory tools remain **plan 05**.
- Design and validation status live in [indexing-design.md](indexing-design.md) and [indexing-validation.md](indexing-validation.md).

## Identity and removal

- `Project.id` is an auto-increment integer, stable across scans, renaming, and explicit administrator path rebinding.
- Trimmed, case-insensitive display names must be unique. Canonical directories and normalized Git origins must also be unique. A second checkout with the same origin is rejected, not silently merged. Non-Git/no-origin directories are supported.
- Git inspection failures are registration errors, not evidence of a non-Git/no-origin directory. For mounted worktrees, make the linked worktree and common Git metadata available read-only at the paths recorded in `.git`.
- Editing the directory rebinds the **same registration ID**. Nothing infers identity from a basename, session number, name, or origin.
- A scan publishes results only if the registration still refers to its scanned directory. Filesystem discovery runs outside the short Doctrine write transaction.
- Deletion removes the Librarian registration and **owned derived indexes** (generation rows and generation-scoped Postgres store tables). It never removes the project directory, `.hatfield`, OM database, journals, or external/symlink destinations.
- Generated migrations add Librarian-owned tables only. PostgreSQL migrations live under `migrations/postgres`. The actual development application uses PostgreSQL.

## Source settings contract

Discovery resolves settings with Hatfield YAML overlay semantics:

1. Source defaults: `.hatfield/extensions-data/observational-memory/om.sqlite` and `.hatfield/sessions` under the selected project.
2. Explicit Hatfield user-home `.hatfield/settings.yaml`.
3. Project `.hatfield/settings.yaml`.

Associative maps merge recursively; lists/scalars/null replace. Missing optional settings files are valid; malformed YAML is not. OM must be enabled as `Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension`.

- OM override: `observational_memory.storage.database`.
- Session override: `sessions.path` with `~` / `%kernel.project_dir%` expansion only.
- `HATFIELD_USER_HOME` is required; `HATFIELD_APP_ROOT` when `%kernel.project_dir%` is used; optional `HATFIELD_PATH_MAPPINGS` JSON longest-prefix maps.
- Enter an absolute directory path visible to the Librarian service, e.g. `/projects/my-project`. There is no directory picker or security root allowlist for this local single-admin utility.

Supported OM schema versions remain through `20261021_006_semantic_source_revisions`. Source change journal rows are **hints**, not a durable independent consumer feed, and are never consumed. Ordinary metadata revision advance is the canonical change signal.

## Derived indexing (plan 04)

Approved baseline:

- CodeRankEmbed explicit **768-dim** vectors (existing local endpoint)
- Official Symfony AI **Postgres** store **0.15.0** on shared PostgreSQL + pgvector (`pgvector/pgvector:pg17-bookworm`)
- Stock `HybridQuery` weighted similarity + PostgreSQL `ts_rank` (lexical match required; not BM25/RRF)
- Isolation by per-project/per-generation store table names (no native date filters)
- Local BGE rerank batches **8**, 768-character truncation, floor **-4**
- Deterministic UUIDv5 store document IDs from `[generation ID, kind, sourceId, full parent signature, chunk position]`; format version **3** participates in the generation signature. Application entities keep auto-increment integers

Notes:

- Native Generic platform handles CodeRank embeddings; the Voyage request client is used only as the **local BGE protocol** adapter. No hosted Voyage provider is selected.
- Embeddings/source reads happen outside the short atomic PostgreSQL transaction that upserts/removes chunks and commits the relational manifest.
- All derived index data and checkpoints share PostgreSQL; there is no cross-store recovery protocol.
- Journals support exact recall later; they are not embedded in the initial corpus.

Admin list and detail show human indexing status, indexed-memory counts, and last successful index time, distinct from discovered source counts. Pending/stale status is honest: discovery is not indexing. Live Mercure + Turbo Streams replace only the source/index status sections; EasyAdmin keeps Turbo Drive disabled for native form/confirmation scripts.

Config surface:

- `DATABASE_URL`
- `CODERANK_URL`, `CODERANK_MODEL`, `CODERANK_ARTIFACT`
- `BGE_URL`, `BGE_MODEL`
- `INDEX_INTERVAL`, `LOCK_DSN`

Worker consumes Messenger `async` and `scheduler_default`. Scheduler uses `CallbackMessageProvider` to yield indexing jobs directly (default interval 60s).

## Docker source mounts

`PROJECTS_HOST_PATH` defaults to this checkout (`.`) and is bound read-only at `/projects` in development/production. Web and Messenger consumer need the same source mounts/env. Optional ignored `compose.sources.yaml` is layered by Castor for **dev/prod only**, never for the isolated browser stack. Production consumer service name is `messenger` (not `worker`).

EasyAdmin uses full-page navigation and the separate `admin` AssetMapper entry so the public Tailwind reset stays out of admin. Login uses EasyAdmin's built-in dark template.

## Validation evidence

Saved coverage remains **one** Projects journey: register/discover → wait for live automatic indexing status → search a public synthetic observation → ordinary harness-owned fixture content/revision update → optional Check for updates / live status → updated search → stable-ID rebind/delete with source-hash checks. Fixture mutation is harness-owned and restored; app code must not mutate sources.

Do not claim plan 04 acceptance from docs alone. Prefer [indexing-design.md](indexing-design.md), [indexing-validation.md](indexing-validation.md), and [TESTING.md](../TESTING.md).
