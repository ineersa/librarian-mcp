# Stage 4 — Ingestion and Indexing Pipeline

## Goal
After a library is saved, clone its repository and run `vera index` asynchronously.

## Architecture decisions

| # | Decision | Choice | Why |
|---|----------|--------|-----|
| 1 | Message transport | `async` only (Doctrine transport) | Long-running git/vera must not block web process |
| 2 | Re-sync strategy | Delete & re-clone | Simpler than git pull; avoids dirty state edge cases |
| 3 | Cleanup logic location | `LibraryManager::prepareCloneDirectory()` | VeraCli stays a pure CLI wrapper; LibraryManager owns filesystem orchestration |
| 4 | Auto-dispatch on create | Yes — `LibraryManager::create()` calls `markQueued()` after flush | Admin adds a library → sync starts immediately |
| 5 | Edit + re-sync | "Sync now" button only | No "Save & Sync" combo; two clicks is fine for MVP |
| 6 | Handler structure | Single `__invoke()` method | Linear pipeline, no reuse case for extracted methods |
| 7 | Flush strategy | Flush after every status transition | Accurate observability; admin sees `Indexing` while sync runs |
| 8 | Error content | Just the exception message | `VeraCliException` already includes command + stderr; truncated to 2000 chars by entity |
| 9 | Failure handling | All failures → `UnrecoverableMessageHandlingException` | No retries; admin retries manually via "Sync now". Message lands in `failed` transport for visibility |
| 10 | Branch checkout | `git clone --branch` only | No separate ref/tag support; `branch` field covers MVP |
| 11 | Tests | E2e with `zenstruck/messenger-test` | Real `git clone` + `vera index` of `zenstruck/messenger-test` repo. Full integration, no mocks |
| 12 | Test transport config | `test://` in `config/packages/test/messenger.yaml` | Zenstruck test transport replacement |
| 13 | Test cleanup | Don't clean up cloned repo | `prepareCloneDirectory()` nukes before clone; DAMA resets DB |
| 14 | VeraCli `alreadyCloned` guard | Remove | `prepareCloneDirectory()` is the authority; `git clone` fails on its own if dir is dirty |
| 15 | VeraCli mkdir | Move to `LibraryManager::prepareCloneDirectory()` | VeraCli is a thin CLI wrapper; no filesystem setup responsibility |
| 16 | Mercure publish | Yes — in the handler after each status flush | Handler publishes JSON to `libraries` topic |
| 17 | Mercure topic | `libraries` (single topic) | Simple; Stimulus controller checks `libraryId` against DOM rows |
| 18 | Frontend listener | Lazy-loaded Stimulus controller | JSON from Mercure → DOM update on status badge. Zero overhead on non-admin pages |
| 19 | Concurrent sync guard | Guard at handler start: skip if not `Queued` | Prevents double-click / stale message race conditions |
| 20 | Mercure in tests | No Mercure assertions in tests | Test the handler pipeline; Mercure publish is infrastructure detail |

## Handler flow (`SyncLibraryMessageHandler::__invoke`)

```
1. Load Library by libraryId (throw if not found)
2. Guard: if status !== Queued → return silently
3. $library->syncStarted() → flush          (Queued → Indexing)
4. Publish Mercure: {libraryId, status: "indexing"}
5. $libraryManager->prepareCloneDirectory()  (nuke + ensure parent dirs)
6. $veraCli->cloneRepository($absPath, gitUrl, branch)
7. $veraCli->indexLibrary($absPath, veraConfig)
8. $library->syncSucceeded() → flush        (Indexing → Ready)
9. Publish Mercure: {libraryId, status: "ready"}
10. Catch any \Throwable:
    - $library->syncFailed($e->getMessage()) → flush  (Indexing → Failed)
    - Publish Mercure: {libraryId, status: "failed", lastError}
    - Throw UnrecoverableMessageHandlingException
```

## Skills to load during implementation

- **`stimulus`** — for the Mercure listener Stimulus controller
- **`symfony-ux`** — for overall UX stack decisions (Mercure integration pattern)

## Trigger points

| Trigger | Mechanism |
|---------|-----------|
| Library created | `LibraryManager::create()` → `markQueued()` → dispatches `SyncLibraryMessage` |
| Admin clicks "Sync now" | `LibraryCrudController` → `LibraryManager::markQueued()` → dispatches `SyncLibraryMessage` |

## File list

### New files
1. `src/MessageHandler/SyncLibraryMessageHandler.php` — the handler
2. `assets/controllers/library-status-controller.js` — lazy-loaded Stimulus controller (Mercure listener)
3. `config/packages/test/messenger.yaml` — test transport override (`test://`)
4. `tests/Application/Admin/SyncLibraryTest.php` — e2e test with `zenstruck/messenger-test`

### Modified files
5. `src/Vera/VeraCli.php` — remove `alreadyCloned` guard + mkdir from `cloneRepository()`
6. `src/Vera/VeraCliException.php` — remove `alreadyCloned()` factory method
7. `src/Service/LibraryManager.php` — add `prepareCloneDirectory()`, add `markQueued()` call in `create()`
8. `config/packages/messenger.yaml` — add routing `App\Message\SyncLibraryMessage: async`
9. `src/Controller/Admin/LibraryCrudController.php` — add `data-controller="library-status"` + `data-library-id` attributes on library rows

## Test plan

Using `zenstruck/messenger-test` as the fixture repo (small, public GitHub repo).

**Test cases:**

1. **Happy path** — create library → assert message dispatched → process transport → library reaches `Ready` → repo exists on disk → `acknowledged()` count 1
2. **Failure path** — mock scenario: library with invalid git URL → process transport → library reaches `Failed` → `lastError` populated → `rejected()` count 1
3. **Dispatch on create** — `LibraryManager::create()` → assert `SyncLibraryMessage` on queue
4. **Concurrent guard** — library already in `Indexing` status → process message → handler returns silently → no status change

## Operations note

Messenger worker must be running for async processing:
```bash
castor dev:messenger-consume
```

## Acceptance criteria

- [ ] Creating a library auto-dispatches `SyncLibraryMessage`
- [ ] Message is routed to `async` transport
- [ ] Handler clones repo to `data/libraries/<path>`
- [ ] Handler runs `vera index` on cloned repo
- [ ] Library reaches `Ready` on success
- [ ] Library reaches `Failed` on error with `lastError` populated
- [ ] Failures throw `UnrecoverableMessageHandlingException` (no retries)
- [ ] Admin can retry via "Sync now" (Failed → Queued)
- [ ] Mercure publishes JSON to `libraries` topic on status changes
- [ ] Stimulus controller updates status badge in real-time on list page
- [ ] Concurrent sync guard prevents race conditions
- [ ] E2e tests pass with real git clone + vera index
