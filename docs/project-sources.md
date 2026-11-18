# Local projects, Hatfield sources, and derived indexing

## Current product boundary

Librarian registers **local directories**. It discovers Hatfield OM/session metadata read-only, then builds Librarian-owned hybrid indexes from retained observations/reflections. It does not boot Hatfield, load extension PHP, run source migrations, or clone repositories.

- **Sources discovered** (`ready`) means discovery succeeded, not that memories are indexed.
- **Search memory** is a per-project list action over derived data. Discovery and incremental indexing run automatically (default 60s), without manual scan/index buttons. Search never synchronously ingests or rebuilds a project and has no date-window UI.
- Public MCP tools (**plan 05**): `ensure_project` (`{}`), `memory_search` (OM schema), and ID-only `recall`; project selected by `X-Librarian-Project`, not a tool argument. Registration uses the same server authentication setting as retrieval, including anonymous mode. See [MCP.md](MCP.md).
- Design and validation status live in [indexing-design.md](indexing-design.md) and [indexing-validation.md](indexing-validation.md).

## Identity and removal

- `Project.id` is an auto-increment integer, stable across automatic source scans.
- Trimmed, case-insensitive display names must be unique. Canonical directories and normalized Git origins must also be unique. A second checkout with the same origin is rejected, not silently merged. Non-Git/no-origin directories are supported.
- Linked Git worktrees are not supported: registration, existing-project MCP retrieval, source discovery, indexing units, and derived search reject them. `ensure_project` checks this before returning an existing registration. Nothing redirects a linked worktree to its primary checkout or selects another project by origin.
- Git inspection failures are errors, not evidence of a non-Git/no-origin directory. Git's absolute per-checkout and common metadata paths are resolved canonically and must coincide; a `.git` file alone is not a rejection. Primary checkouts, separate-git-dir/submodule layouts with coincident metadata, and non-Git projects remain supported. Existing Git metadata must be accessible at the paths recorded in `.git`, including inside service mounts.
- Registration supports create/delete, not editing. To change its name or directory, remove the registration and register it again. Nothing infers identity from a basename, session number, name, or origin.
- `ensure_project` explicitly registers the canonical header root if needed, using its basename only as the default display name. An existing registration is returned unchanged without enqueueing work. New registrations reuse entity validation, catalog uniqueness constraints, and normal asynchronous source discovery; no `.hatfield` directory or initialized OM is required. Source discovery may initially report missing sources and retry on scheduled checks. Registration never creates or modifies source directories, settings, databases, or journals; success is not indexing readiness.
- A scan publishes results only if the registration still refers to its scanned directory. Filesystem discovery runs outside the short Doctrine write transaction.
- Deletion removes the Librarian registration and **owned derived indexes** (generation rows and generation-scoped Postgres store tables). It never removes the project directory, `.hatfield`, OM database, journals, or external/symlink destinations.
- Generated migrations add Librarian-owned tables only. PostgreSQL migrations live under `migrations/postgres`. The actual development application uses PostgreSQL.

## Source settings contract

Discovery resolves settings with Hatfield YAML overlay semantics:

1. Source defaults: `.hatfield/extensions-data/observational-memory/om.sqlite` and `.hatfield/sessions` under the selected project.
2. Explicit Hatfield user-home `.hatfield/settings.yaml`.
3. Project `.hatfield/settings.yaml`.

Associative maps merge recursively; lists/scalars/null replace. Missing optional settings files are valid; malformed YAML is not. OM must be enabled as `Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension`.

- OM override: `extensions.settings.observational_memory.storage.database`. The obsolete top-level `observational_memory` block is not read. Absolute database paths remain absolute; relative paths are joined to the selected project root, then canonicalized/path-mapped under the existing source rules. There is no fallback to the default database when a configured custom path is unavailable.
- Session override: `sessions.path` with `~` / `%kernel.project_dir%` expansion only.
- `HATFIELD_USER_HOME` is required; `HATFIELD_APP_ROOT` when `%kernel.project_dir%` is used; optional `HATFIELD_PATH_MAPPINGS` JSON longest-prefix maps.
- Enter an absolute directory path visible to the Librarian service, e.g. `/projects/my-project`. There is no directory picker or security root allowlist for this local single-admin utility.

Readiness requires the project root, `.hatfield`, valid settings with OM enabled, and a readable sessions directory, or, for a project without Hatfield OM, a readable Claude Code transcript directory under `CLAUDE_PROJECTS_PATH` (Claude-only projects have no sessions path; their session count is the number of transcripts). Hatfield-ready projects also record the Claude transcript directory in source details when present. The OM database is **optional**: Librarian owns memory in PostgreSQL (plan 09) and reads `om.sqlite` only for a **one-time** legacy import: the first successful discovery that finds it imports it and records `legacy_imported_at` on the project; later scans and deploys never re-import, so memory deleted in Librarian stays deleted. Hatfield no longer writes `om.sqlite` after the thin-client cutover; use `app:om:import <project-id>` to re-run an import deliberately. A missing file is normal; a present but unreadable or unsupported file is reported as a source issue and its import is skipped, without blocking readiness or indexing.

Supported OM schema versions remain through `20261021_006_semantic_source_revisions`. Source change journal rows are **hints**, not a durable independent consumer feed, and are never consumed. Ordinary metadata revision advance is the canonical change signal.

## Derived indexing (plan 04)

Approved baseline:

- CodeRankEmbed explicit **768-dim** vectors (existing local endpoint)
- Official Symfony AI **Postgres** store **0.15.0** on shared PostgreSQL + pgvector (`pgvector/pgvector:pg17-bookworm`)
- Stock `HybridQuery` weighted similarity + PostgreSQL `ts_rank` (lexical match required; not BM25/RRF)
- Isolation by per-project/per-generation store table names; MCP date bounds use HybridQuery `where`/`params` ([postgres-hybrid-filters.md](postgres-hybrid-filters.md))
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

The `worker` consumes Messenger `async` and `scheduler_default`; memory generation runs on the separate `om` transport and `om-worker` consumer. Scheduler uses `CallbackMessageProvider` to yield source-discovery jobs for registered projects (default interval 60s); successful discovery queues incremental indexing. Last source/index check is shown separately from last successful index. An unchanged revision is checked, not rebuilt.

## Docker source mounts

`PROJECTS_HOST_PATH` is bound read-write at `PROJECTS_CONTAINER_PATH` in development and production (defaults `/tmp` → `/projects`, which only keep a fresh checkout bootable). Clients send host paths in `X-Librarian-Project` and Librarian resolves them as-is, so set both to the same absolute host path: in `.env.local` for dev (Castor passes it to Compose) or `.env.prod.local` for prod. `HATFIELD_USER_HOME` is set the same way. Web and Messenger consumer need identical source mounts/env. SQLite connections remain `mode=ro`: the writable mount lets SQLite create/manage WAL/SHM sidecars when absent, not write source tables. Source journals must not be modified. Optional ignored `compose.sources.yaml` is layered by Castor for **dev/prod only**, never for the isolated browser stack; OM database directories there also need writable mounts. Production consumer service names are `messenger` and `messenger-om` (not `worker` / `om-worker`); both need the same mounts.

`CLAUDE_PROJECTS_PATH` (host `~/.claude/projects`) is bound **read-only** at the same path into web and consumers by `compose.override.yaml` / `compose.prod.yaml`; leave it empty to disable Claude transcripts (the empty `docker/claude-projects-placeholder` directory is mounted instead). A project with Claude transcripts stays ready when Hatfield prerequisites (user home, `.hatfield`, settings, OM, sessions) are missing or broken; those are kept as diagnostics. Root, Git-metadata and linked-worktree failures still block it. Transcripts are plain JSONL, so no write access is needed.

EasyAdmin uses full-page navigation and the separate `admin` AssetMapper entry so the public Tailwind reset stays out of admin. Login uses EasyAdmin's built-in dark template.

## Validation evidence

Saved coverage remains **one** Projects journey: register → row-click details → wait for live automatic discovery/index status → per-row search of a public synthetic observation → delete the fixture session through `DELETE /api/om/v1/sessions/hatfield/{id}` → scheduler-only reindex to zero memories, unchanged across later scans (no legacy re-import) → empty search → delete with source-hash checks. Sources are never mutated, by the harness or app code.

Do not claim plan 04 acceptance from docs alone. Prefer [indexing-design.md](indexing-design.md), [indexing-validation.md](indexing-validation.md), and [TESTING.md](../TESTING.md).
