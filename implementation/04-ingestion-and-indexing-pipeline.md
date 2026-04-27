# Stage 4 — Simple ingestion and indexing handler

## Goal
After a library is saved, clone its repository and run `vera index` asynchronously.

## Scope
Keep MVP intentionally simple.
Use Symfony Messenger with one message and one handler.

## Message flow
Use one message:

- `SyncLibraryMessage(libraryId)`

The handler should do only this:

1. load `Library`
2. mark status as `queued` or `indexing`
3. resolve repo path: `/app/data/libraries/<slug>/repo`
4. clone the repo if missing
5. checkout configured ref if provided
6. run `vera index` in that repo
7. update timestamps/status
8. on failure, save the error and mark `failed`

That is enough for MVP.

## MVP simplifications

- no bridge service
- no Vera MCP integration yet
- no progress UI required
- no advanced sync pipeline
- no mandatory tests for this stage if they slow delivery down

If re-sync behavior needs to stay simple, MVP can do one of these:

- delete existing checkout and clone fresh again
- or pull latest changes if the repo already exists

The simplest implementation is acceptable.

## Trigger points
Dispatch `SyncLibraryMessage` when:

- a new library is created
- a library is edited and admin chooses to re-sync
- admin clicks `Sync now` in EasyAdmin

## Implementation notes
Use a dedicated service around Symfony `Process`, for example `VeraCli`, so the handler stays small.

The handler should not assemble shell commands directly.
Instead it should call methods such as:

- `cloneRepository()`
- `checkoutReference()`
- `indexRepository()`

## Optional nice-to-have
If easy enough, publish status updates over Mercure so the admin UI can refresh progress.
But this is optional and should not delay MVP.

## Failure handling
On failure, persist at least:

- `status = failed`
- `lastError`

The admin must be able to retry sync later.

## Operations
Document one important runtime rule clearly:

- Messenger worker must be running via `castor dev:messenger-consume`

## Acceptance criteria

- creating a library can dispatch async sync work
- repo appears under `data/libraries/<slug>/repo`
- `vera index` is executed successfully for a library
- library reaches `ready` or `failed`
- failures are visible in admin UI
