# Librarian MCP — Architecture (current)

## 1) System shape

The app is a Symfony web/admin application that:

1. Stores a catalog of registered local Hatfield projects
2. Asynchronously discovers each project's OM database and session roots read-only
3. Exposes HTTP MCP at `/_mcp` (public tools arrive in plan 05)

Core runtime pieces:

- **Web/admin:** Symfony + EasyAdmin
- **Persistence:** Doctrine ORM + SQLite (Librarian-owned registration/derived state)
- **Async pipeline:** Symfony Messenger (`async` transport)
- **Source discovery:** read-only Hatfield settings / OM / session inspection
- **MCP transport:** Symfony MCP bundle HTTP endpoint

The earlier Git clone + Vera indexing product path is retired. Historical notes remain in [framework-upgrade.md](framework-upgrade.md); current product behavior is project registration and source discovery ([project-sources.md](project-sources.md)).

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
- `lastError`, `lastScanAt`, `lastSuccessfulScanAt`
- `lastSuccessfulIndexAt` remains unset until plan 04 indexing exists

Uniqueness is case-insensitive for names, canonical for directories, and normalized for Git origins. Explicit admin path edits rebind the same project ID; they do not merge registrations by name or origin.

`ready` means sources were discovered successfully. It does **not** mean indexed. Source journal / change counts are Hatfield OM source entries, not a Librarian index backlog.

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

Removal deletes only Librarian registration/derived rows. Source projects, `.hatfield`, OM databases, and journals are never deleted by Librarian.

Details: [project-sources.md](project-sources.md).

---

## 4) Async discovery pipeline

1. Persist / update project registration
2. Queue scan message on Messenger `async`
3. Worker marks health `scanning`, discovers sources read-only
4. On success: health `ready` with discovered paths/counts/schema/revision
5. On failure: health `error` with actionable `lastError`

Duplicate deliveries and unchanged rescans remain idempotent at the registration layer. Periodic scheduling / indexing checkpoints are deferred to plan 04.

---

## 5) Admin + UX integration

- EasyAdmin dashboard shows registered-project counts and recent health
- Project CRUD at `/admin/projects` with inlined actions
- Directory picker browses service-visible directories starting at `PROJECT_BROWSE_ROOT`
- Explicit **Scan sources** action requeues discovery for an existing registration
- Detail page exposes health / source metadata for browser waits (`#project-source-status`, `data-project-health`)

There is no security allowlist beyond admin authentication. Symlinks / external configured paths are allowed by the owner's local-tool policy; actual resolved paths are reported in project health.

---

## 6) Storage layout

Librarian-owned:

- SQLite app DB at `data/app` (dev) / production data mount
- derived registration/discovery state only

Source-owned (read-only mounts):

- `PROJECTS_HOST_PATH` → `/projects` in dev/prod
- optional ignored `compose.sources.yaml` overlays for user/global/custom settings or data (dev/prod via Castor only)
- browser stack uses synthetic `/project-sources` fixtures, never operator overlays

Do not commit private project settings, OM databases, or session journals.

---

## 7) Operational model

- Long-running discovery is async (Messenger worker required)
- Source access is strictly read-only
- MCP transport remains available; public tools are empty until plan 05
- Adding/removing projects does not invent temporary MCP tools

## 8) Framework / MCP upgrade notes (plan 01, historical infrastructure)

- Owner-approved Symfony **8.2.0-BETA1** (2026-11-12); stable 8.2 was unavailable.
- MCP HTTP remains on `/_mcp`; bundle 0.15 uses `mcp.servers.librarian` + explicit registry.
- Dependency decisions are recorded in [framework upgrade notes](framework-upgrade.md).
- Plan 03 supersedes the Git/Vera library product path described there; keep that document as historical upgrade evidence only.
