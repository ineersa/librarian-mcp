# Librarian MCP — Architecture (current)

## 1) System shape

The app is a Symfony web/admin application that:

1. Stores a catalog of registered local Hatfield projects
2. Asynchronously discovers each project's OM database and session roots read-only
3. Builds Librarian-owned hybrid indexes (Chroma + SQLite FTS5) from retained observations/reflections
4. Exposes HTTP MCP at `/_mcp` (public memory tools arrive in plan 05)

Core runtime pieces:

- **Web/admin:** Symfony + EasyAdmin
- **Persistence:** Doctrine ORM + SQLite (Librarian-owned registration / generation / parent metadata)
- **Async pipeline:** Symfony Messenger (`async`) + Scheduler (`scheduler_default`)
- **Vector store:** private Chroma service (image tag 1.0.21; `/version` may report 1.0.0)
- **Lexical store:** SQLite FTS5/BM25 in Librarian-owned files under `INDEX_STORAGE_DIR`
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
- Chroma collections and lexical SQLite files are Librarian-owned derived data. Registration deletion removes those owned indexes, never source trees.

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
5. Index worker acquires the project execution lock, reads bounded source batches, embeds, writes Chroma + lexical stores, advances checkpoints only after both backends acknowledge
6. Scheduler tick enqueues periodic updates (`INDEX_INTERVAL`) without a custom polling daemon
7. On failure: sanitized `lastError` / generation error state; Messenger retries remain replay-safe for deterministic IDs

Search remains available against the last committed usable index while newer work is pending where the store supports it. Stale/partial status is reported explicitly.

---

## 5) Admin + UX integration

- EasyAdmin dashboard shows registered-project counts and recent health
- Project CRUD at `/admin/projects` with inlined actions
- Directory picker browses service-visible directories starting at `PROJECT_BROWSE_ROOT`
- Explicit **Scan sources**, **Index now**, and **Search memory** actions
- Detail page exposes source health (`#project-source-status`) and index status (`#project-index-status`) with bounded meta refresh while pending
- Admin uses the separate `admin` AssetMapper entry (no public Tailwind reset)

There is no security allowlist beyond admin authentication. Symlinks / external configured paths are allowed by the owner's local-tool policy; actual resolved paths are reported in project health.

---

## 6) Storage layout

Librarian-owned:

- SQLite app DB at `data/app` (dev) / production data mount
- relational generations / indexed parents
- lexical indexes under `INDEX_STORAGE_DIR` (browser: `var/browser-live/indexes`)
- Chroma owned volume (private network; no public host port)

Source-owned (read-only mounts):

- `PROJECTS_HOST_PATH` → `/projects` in dev/prod
- optional ignored `compose.sources.yaml` overlays for user/global/custom settings or data (dev/prod via Castor only)
- browser stack uses synthetic `/project-sources` fixtures, never operator overlays

Do not commit private project settings, OM databases, session journals, or derived index files.

---

## 7) Operational model

- Long-running discovery/indexing is async (Messenger worker required; consumes `async` + `scheduler_default`)
- Source access is strictly read-only
- Models remain external local endpoints (`CODERANK_*`, `BGE_*`); Chroma is `CHROMA_HOST`/`CHROMA_PORT`
- MCP transport remains available; public tools are empty until plan 05
- No production cutover/migration authorization is implied by plan 04 implementation

## 8) Framework / MCP upgrade notes (plan 01, historical infrastructure)

- Owner-approved Symfony **8.2.0-BETA1** (2026-11-12); stable 8.2 was unavailable.
- MCP HTTP remains on `/_mcp`; bundle 0.15 uses `mcp.servers.librarian` + explicit registry.
- Dependency decisions are recorded in [framework upgrade notes](framework-upgrade.md).
- Plans 03/04 supersede the Git/Vera library product path described there; keep that document as historical upgrade evidence only.
