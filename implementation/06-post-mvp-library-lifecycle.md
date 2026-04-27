# Stage 6 — Post-MVP library lifecycle management

## Goal
Cover the first wave of improvements after MVP:

- update index
- pull/update library source
- support multiple versions / branches
- expose more Vera settings in the UI

## Why this is a separate stage
These features change the data model and job orchestration enough that they should not be mixed into MVP.

## Scope

### 1. Split logical library from indexed version
Introduce a second entity, for example:

- `Library` — stable identity, display name, description
- `LibraryVersion` — branch/tag/ref-specific checkout and index state

Suggested `LibraryVersion` fields:

- `id`
- `library`
- `label` *(e.g. `main`, `v1.0`, `2.x`)*
- `gitRef`
- `status`
- `repoPath`
- `currentRevision`
- `veraConfig`
- `lastPulledAt`
- `lastIndexedAt`
- `lastError`

That will support multiple branches/tags cleanly.

### 2. Add explicit maintenance actions
Per version, add actions such as:

- `Pull latest`
- `Reindex`
- `Reclone + reindex`
- `Disable`

These should dispatch separate Messenger messages so failures are easier to reason about.

### 3. Track revision and freshness
After sync, persist:

- checked-out commit SHA
- branch/tag name
- last successful pull time
- last successful index time

That lets the UI clearly answer: “what exactly is indexed right now?”

### 4. Better Vera settings UI
Instead of a raw JSON-ish MVP form, move to a structured editor grouped by concern:

- indexing
- ignore/exclude behavior
- backend/performance
- retrieval defaults where appropriate

A good compromise is:

- curated common fields shown by default
- advanced section behind disclosure
- keep unsupported settings out of MVP paths

## MCP impact
Once versions exist, `library-query` will need an optional selector such as:

- `version`

If omitted, it should use the library's configured default version.

## Tests

Add tests for:

- creating multiple versions for one library
- pulling latest revision
- reindexing one version without touching another
- querying explicit version through MCP

## Acceptance criteria

- one logical library can have multiple indexed versions
- admin can refresh source and reindex deliberately
- UI makes indexed revision visible
- common Vera settings are editable without exposing every raw key
