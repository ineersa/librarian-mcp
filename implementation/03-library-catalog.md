# Stage 3 — Library catalog and CRUD

## Goal
Let an authenticated admin manage the catalog of libraries that this app knows about.

## Scope

### `Library` entity
Suggested fields:

- `id` (ULID)
- `name`
- `slug`
- `gitUrl`
- `defaultRef` *(branch/tag/commitish; nullable, default `main`/repo default)*
- `description` *(2–3 sentence summary entered by user)*
- `status` *(draft, queued, indexing, ready, failed)*
- `veraConfig` *(JSON for MVP-safe settings)*
- `lastError` *(nullable text)*
- `lastSyncedAt` *(nullable)*
- `lastIndexedAt` *(nullable)*
- `createdBy` *(User)*
- `createdAt`
- `updatedAt`

## Vera settings to expose in MVP
Keep the form intentionally small.

Recommended fields:

- `excludePatterns` (multi-line textarea, one glob per line)
- `noIgnore` (checkbox)
- `noDefaultExcludes` (checkbox)

Do **not** try to expose every Vera config key yet.
That belongs to a later stage.

## CRUD UI
Use EasyAdmin CRUD instead of building custom Twig pages.

That means:

- one `LibraryCrudController`
- EasyAdmin index/detail/new/edit actions
- a custom EasyAdmin action for `Sync now`

## List behavior
The admin list should support:

- partial search by library name
- status badge
- last indexed timestamp
- actions: detail, edit, delete, sync now

## Validation rules

- `name` required
- `slug` required and unique
- `gitUrl` required and must be a valid GitHub URL for MVP
- `description` required but short enough for clean display
- exclude patterns normalized into an array before persistence

## Delete behavior
For MVP, delete should remove:

- database record
- local cloned directory under `data/libraries/<slug>`

Prefer an explicit EasyAdmin delete confirmation flow.

## UI notes

- use EasyAdmin forms and detail view
- no fancy visualization yet
- show a read-only preview of normalized Vera settings on the detail page

## Acceptance criteria

- admin can fully manage library records from EasyAdmin
- library records persist all MVP metadata
- partial search by library name works
- delete flow is explicit and safe

## Dependency on next stage
This stage stores library metadata only.
Actual clone/index work is executed asynchronously in Stage 4.
