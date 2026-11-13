# Librarian MCP — Architecture (current)

## 1) System shape

The app is a Symfony web/admin application that:

1. Stores a catalog of registered local Hatfield projects
2. Asynchronously discovers each project's OM database and session roots read-only
3. Builds Librarian-owned hybrid indexes from retained observations/reflections
4. Exposes HTTP MCP at `/_mcp` (public memory tools arrive in plan 05)

Core runtime pieces:

- **Web/admin:** Symfony + EasyAdmin
- **Persistence + store:** Doctrine ORM + official Symfony AI Postgres store **0.15.0** on shared PostgreSQL + pgvector (`pgvector/pgvector:pg17-bookworm`, digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`)
- **Async pipeline:** Symfony Messenger (`async`) + Scheduler (`scheduler_default`)
- **Models:** local CodeRankEmbed (explicit 768-dim vectors) + local BGE reranker
- **Source discovery:** read-only Hatfield settings / OM / session inspection
- **MCP transport:** Symfony MCP bundle HTTP endpoint

The earlier Git clone + Vera indexing product path is retired. Historical notes remain in [framework-upgrade.md](framework-upgrade.md). Current product behavior: [project-sources.md](project-sources.md) and [indexing-design.md](indexing-design.md).

---

## 2) Domain model

## `Project`

Main fields (product contract):

- `id` (int auto-increment; stable identity across rescans and explicit path rebinds)
- `name` (unique after trim + case-insensitive compare)
- `rootPath` (unique canonical project directory)
- `repositoryOrigin` (unique normalized Git origin when present)
- `health` (`pending` | `scanning` | `ready` | `error`)
- discovered source paths / schema / counts / revision / source-change journal entries
- `lastError`, `lastScanAt`, `lastSuccessfulScanAt`, `lastSuccessfulIndexAt`

Uniqueness is case-insensitive for names, canonical for directories, and normalized for Git origins. Explicit admin path edits rebind the same project ID; they do not merge registrations by name or origin.

`ready` means sources were discovered successfully. It does **not** mean indexed. Source journal / change counts are Hatfield OM source entries, not a Librarian index backlog.

## Index ownership

- `IndexGeneration` tracks signature, source identity/binding, active flag, state, committed revision, parent/chunk counts, and sanitized errors.
- `IndexedMemory` stores committed parent provenance for recall.
- Generation-scoped Postgres store tables are Librarian-owned derived data. Registration deletion removes those owned indexes, never source trees.
- Store document IDs are deterministic UUIDv5 values derived from `[generation ID, kind, sourceId, full parent signature, chunk position]`; format version 3 participates in the generation signature. Application entity keys remain auto-increment integers.

---

## 3) Service boundaries

## Project registration / scan queue

Admin registration persists Librarian-owned metadata and enqueues async read-only discovery. MCP callers cannot supply arbitrary filesystem paths to register or scan.

Discovery:

- resolves Hatfield settings overlays (`defaults < user < project` YAML)
- honors `HATFIELD_USER_HOME` (required), optional `HATFIELD_APP_ROOT`, and `HATFIELD_PATH_MAPPINGS`
- locates OM database and session roots from effective settings
- validates supported OM schema versions through `20261021_006_semantic_source_revisions`
- never writes source trees, never boots Hatfield runtime/extension code, never migrates source DBs

Successful discovery may enqueue indexing. Explicit **Index now** also queues through `ProjectIndexManager` only. Search uses the active committed generation and never synchronously rebuilds.

Removal deletes Librarian registration and owned derived indexes. Source projects, `.hatfield`, OM databases, and journals are never deleted by Librarian.

Details: [project-sources.md](project-sources.md), [indexing-design.md](indexing-design.md).

---

## 4) Async discovery + indexing pipeline

1. Persist / update project registration
2. Queue scan message on Messenger `async`
3. Worker marks health `scanning`, discovers sources read-only
4. On success: health `ready` with discovered paths/counts/schema/revision; indexing may be queued
5. Index worker acquires the project execution lock, reads bounded source batches, embeds outside short DB writes, then upserts/removes store chunks + commits the relational manifest in one short PostgreSQL transaction
6. Scheduler `CallbackMessageProvider` yields project indexing jobs every `INDEX_INTERVAL` seconds (default 60) without a custom tick protocol or OS cron
7. On failure: sanitized `lastError` / generation error state; Messenger retries remain replay-safe for deterministic IDs

Search remains available against the last committed usable index while newer work is pending. Stale/partial status is reported explicitly. Stock hybrid requires lexical match and does not apply date filters.

---

## 5) Admin + UX integration

- EasyAdmin dashboard shows registered-project counts and recent health
- Project CRUD at `/admin/projects` with inlined actions
- Directory picker browses service-visible directories starting at `PROJECT_BROWSE_ROOT`
- Explicit **Scan sources**, **Index now**, and **Search memory** actions (no date-window UI)
- Detail page exposes source health (`#project-source-status`) and index status (`#project-index-status`) with bounded meta refresh while pending
- Admin uses the separate `admin` AssetMapper entry (no public Tailwind reset)

There is no security allowlist beyond admin authentication. Symlinks / external configured paths are allowed by the owner's local-tool policy; actual resolved paths are reported in project health.

---

## 6) Storage layout

Librarian-owned:

- Shared PostgreSQL + pgvector database for Doctrine metadata and generation-scoped store tables
- Prior SQLite migration history retained; new baseline migrations under `migrations/postgres` (`DoctrineMigrations\Postgres`)
- Actual development and browser stacks use PostgreSQL; the retired SQLite/Chroma storage was removed after preserving application records

Source-owned (read-only mounts):

- `PROJECTS_HOST_PATH` → `/projects` in dev/prod
- optional ignored `compose.sources.yaml` overlays for user/global/custom settings or data (dev/prod via Castor only)
- browser stack uses synthetic `/project-sources` fixtures, never operator overlays

Do not commit private project settings, OM databases, session journals, or derived index data.

---

## 7) Operational model

- Long-running discovery/indexing is async (Messenger worker required; consumes `async` + `scheduler_default`)
- Source access is strictly read-only
- Models remain external local endpoints (`CODERANK_*`, `BGE_*`)
- MCP transport remains available; public tools are empty until plan 05
- Browser database is `librarian_browser` in the checkout-scoped Postgres volume; browser-only Postgres publishes a dynamic `127.0.0.1` host port for the saved harness; production/dev Postgres publish no host ports

## 8) Framework / MCP upgrade notes (plan 01, historical infrastructure)

- Owner-approved Symfony **8.2.0-BETA1** (2026-11-12); stable 8.2 was unavailable.
- MCP HTTP remains on `/_mcp`; bundle 0.15 uses `mcp.servers.librarian` + explicit registry.
- Dependency decisions are recorded in [framework upgrade notes](framework-upgrade.md).
- Plans 03/04 supersede the Git/Vera library product path described there; keep that document as historical upgrade evidence only.
