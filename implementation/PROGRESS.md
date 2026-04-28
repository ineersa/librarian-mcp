# Implementation Progress

## Stage 1 — Vera CLI Foundation ✅

**Status:** Complete
**Date:** 2026-04-27

### What was done

#### Docker: vera binary in FrankenPHP container

- **`Dockerfile`** — Added vera v0.11.8 musl binary download to `frankenphp_base` stage (available in both dev and prod targets). Uses the statically-linked `x86_64-unknown-linux-musl` build because Bookworm's glibc is 2.36 and the gnu build requires 2.39.
- **`compose.override.yaml`** — Added vera API env vars (`EMBEDDING_MODEL_BASE_URL`, `RERANKER_MODEL_BASE_URL`, `VERA_COMPLETION_BASE_URL`, etc.) pointing to `host.docker.internal` so vera inside the container reaches host-local llama.cpp services. Added `./docker/vera:/root/.vera:delegated` volume mount.
- **`docker/vera/`** — Project-local vera config directory (committed). Contains `config.json` (API backend + tuning defaults) and `credentials.json` (all `not-needed`). Runtime state (`lib/`, `models/`, `update-check.json`) is gitignored. `README.md` documents the setup.
- **`.gitignore`** — Added `/docker/vera/lib/`, `/docker/vera/models/`, `/docker/vera/update-check.json`.

#### Symfony: VeraCli service

- **`src/Vera/VeraCli.php`** — Service wrapping the vera CLI via Symfony `Process`. Slug-validated path construction (`data/libraries/<slug>/repo`), timeouts, structured error handling. Methods: `cloneRepository()`, `indexLibrary()`, `searchLibrary()`, `isAvailable()`, `getVersion()`. Search runs with CWD set to the repo path (vera discovers `.vera/` index from working directory).
- **`src/Vera/VeraCliException.php`** — Structured exception with factory methods (`commandFailed`, `processError`, `alreadyCloned`, `notCloned`), carries original command and exit code.
- **`src/Vera/Command/VeraCheckCommand.php`** — `app:vera:check` console command for smoke-testing vera availability.
- **`config/services.yaml`** — Registered `VeraCli` with `%kernel.project_dir%`, `%app.vera.binary%`, `%app.vera.timeout%` parameters.
- **`.env`** — Added `VERA_BINARY=vera` and `VERA_TIMEOUT=300` env vars.

### Verified

- `vera 0.11.8` runs inside the PHP container (`docker compose exec php vera --version`)
- Symfony container lints clean with `VeraCli` registered
- `php bin/console app:vera:check` → `[OK] Vera CLI is available: vera 0.11.8`
- `vera index /app` → 186 files, 1713 chunks indexed
- `vera search "..." --json` returns ranked results with file paths, line ranges, content, symbol info
- All API env vars reach `host.docker.internal` (embedding: 8059, reranker: 8060, completion: 8052)
- `docker/vera/config.json` committed with tuning defaults; env vars override endpoints at runtime

### Key decisions made

| Decision | Choice | Why |
|---|---|---|
| Vera runtime | Binary inside FrankenPHP container | No sidecar needed; Symfony `Process` calls vera directly |
| Binary variant | `x86_64-unknown-linux-musl` | Static linking; Bookworm glibc 2.36 < required 2.39 |
| Vera config | `docker/vera/` in repo, mounted at `/root/.vera` | Self-contained; no `~/.vera` host dependency |
| API endpoint routing | Env vars in compose override `localhost` → `host.docker.internal` | Same config.json works on host and in Docker |
| Path safety | Slug validation (`[a-z0-9][a-z0-9-]*`) | No arbitrary user-supplied paths reach vera/git |

### Next stage

**Stage 2 — Backoffice Security** (`02-backoffice-security.md`)
- Admin user entity + authentication
- Login/logout flow
- Session-based browser auth

---

## Stage 2 — Backoffice Security ✅

**Status:** Complete
**Date:** 2026-04-27

### What was done

#### User entity + Doctrine persistence
- **`src/Entity/User.php`** — Doctrine entity with ULID primary key, email, hashed password, roles JSON array, timestamps. Implements `UserInterface` + `PasswordAuthenticatedUserInterface`. Includes transient `plainPassword` field for admin CRUD forms.
- **`src/Repository/UserRepository.php`** — Service entity repository with `PasswordUpgraderInterface` for automatic rehashing.
- **`migrations/Version20260427194935.php`** — Creates `users` table (id BLOB ULID, email VARCHAR unique, roles JSON, password, timestamps) + `messenger_messages` table.

#### Symfony Security configuration
- **`config/packages/security.yaml`** — Replaced in-memory provider with Doctrine entity provider (`app_user_provider` → `User::class` by `email`). Added form login firewall (`login_path: app_login`, `enable_csrf: true`, `default_target_path: admin`). Added logout path targeting `app_home`. Access control: `^/admin` requires `ROLE_ADMIN`, `^/login` is `PUBLIC_ACCESS`.

#### Login/logout flow
- **`src/Controller/LoginController.php`** — Handles `/login` (GET/POST) via `AuthenticationUtils`, redirects to admin if already authenticated. `/logout` route handled by Symfony security system.
- **`templates/login/index.html.twig`** — Tailwind-styled login form with CSRF protection, error display, and responsive centered layout.

#### EasyAdmin integration
- **`easycorp/easyadmin-bundle` v5.0.6** installed via Composer.
- **`config/routes/easyadmin.yaml`** — Auto-generated route loader.
- **`src/Controller/Admin/DashboardController.php`** — EasyAdmin dashboard at `/admin`, title "Librarian MCP", sidebar with Dashboard, Users, Logout, and Back to site links. Protected by `#[IsGranted('ROLE_ADMIN')]`.
- **`src/Controller/Admin/UserCrudController.php`** — Full CRUD for User entity: list/search/filter/sort by email, roles. Password hashing via `UserPasswordHasherInterface` in `persistEntity`/`updateEntity`. Plain password field only on forms (required on new, optional on edit). Roles choice field with `ROLE_ADMIN` option (ROLE_USER auto-assigned).

#### Admin user creation command
- **`src/Command/CreateAdminCommand.php`** — `app:user:create-admin` console command. Accepts email as argument, password via `--password` option or interactive hidden prompt. Checks for duplicate email. Hashes password and assigns `ROLE_ADMIN`.

### Verified
- `castor dev:console "doctrine:migrations:migrate --no-interaction"` → migration applied
- `castor dev:console "app:user:create-admin admin@test.com --password=admin"` → admin created
- `castor dev:console "lint:container"` → container lints clean
- `castor dev:console "debug:router"` → all routes registered (admin, admin_user_*, app_login, app_logout, app_home)
- Browser: `/admin` redirects to `/login` when unauthenticated
- Browser: Login with `admin@test.com` / `admin` → redirects to `/admin` dashboard
- Browser: EasyAdmin dashboard shows sidebar (Dashboard, Users, Logout, Back to site) and user header
- Browser: Users CRUD page lists admin user with ID, Email, Roles, Created At, Updated At columns
- Browser: Homepage `/` remains publicly accessible (200)
- PHPStan passes (only pre-existing dead-code warnings on Vera classes and unused-entity-method warnings on User)
- CS Fixer passes (2 files auto-fixed)

### Key decisions made

| Decision | Choice | Why |
|---|---|---|
| User primary key | ULID | Consistent with spec; time-sortable, no auto-increment leak |
| Password hashing in CRUD | Override `persistEntity`/`updateEntity` | EasyAdmin pattern; keeps password handling centralized in CRUD controller |
| Login form styling | Tailwind via base template | Consistent with project conventions; no extra CSS file |
| Admin creation | Console command | No manual DB access needed; repeatable in CI/staging |
| EasyAdmin version | v5.x | Latest stable; requires `#[AdminDashboard(routePath:, routeName:)]` instead of `#[Route]` on index |
| Roles field | ChoiceField with ROLE_ADMIN only | MVP simplicity; ROLE_USER always auto-assigned |

### Tests added

- **`tests/Application/Admin/SecurityTest.php`** — 7 tests covering:
  - `/admin` redirects anonymous to `/login`
  - `/admin` denies non-admin (ROLE_USER) with 403
  - `/admin` allows ROLE_ADMIN
  - Login form rejects bad credentials + shows error
  - Login form accepts valid credentials + redirects to dashboard
  - Login page redirects already-logged-in admin to dashboard
  - Logout redirects and clears session
- **`tests/Application/Admin/UserCrudTest.php`** — 4 tests covering:
  - User index page loads with correct heading
  - User index lists existing admin user in table
  - User create page loads with form
  - User detail page loads and shows user email

All 12 tests pass (`castor dev:test` → tests=12, assertions=30, errors=0, failures=0).

### Next stage

**Stage 3 — Library Catalog** (`03-library-catalog.md`)
- Library entity + CRUD
- Library list and detail pages

## Stage 3 — Library Catalog ✅

**Status:** Complete
**Date:** 2026-04-28

### What was done

#### Library entity + Doctrine persistence
- **`src/Entity/Library.php`** — Doctrine entity with auto-increment int PK, asymmetric visibility via getters/setters for all fields. Properties: `name`, `slug` (unique), `gitUrl`, `branch`, `path` (unique, immutable), `description`, `status` (backed enum), `veraConfig` (JSON → `VeraIndexingConfig` VO), `lastError`, `lastSyncedAt`, `lastIndexedAt`, timestamps. Domain methods for status transitions: `markQueued()`, `syncStarted()`, `syncFailed()`, `syncSucceeded()` — each enforces allowed transitions and throws `LogicException` on invalid ones.
- **`src/Entity/LibraryStatus.php`** — Backed enum: `Draft`, `Queued`, `Indexing`, `Ready`, `Failed`.
- **`src/Repository/LibraryRepository.php`** — Service entity repository with `findOneByPath()` and `findOneBySlug()`.
- **`src/Vera/VeraIndexingConfig.php`** — Readonly value object for vera indexing config (`excludePatterns`, `noIgnore`, `noDefaultExcludes`) with `fromArray()`/`toArray()` for JSON persistence.
- **`src/Message/SyncLibraryMessage.php`** — Message class carrying `libraryId`. Handler is Stage 4's concern.
- **`migrations/Version20260428015040.php`** — Creates `libraries` table with unique indexes on `slug` and `path`.

#### LibraryManager service
- **`src/Service/LibraryManager.php`** — All business logic:
  - `deriveName()` — parses `owner/repo` from git URL, appends branch if not `main`
  - `generateSlug()` — slugifies name via Symfony Slugger
  - `computePath()` — `owner/repo/branch` from git URL + branch
  - `create()` — computes fields, unique path/slug validation, persists
  - `update()` — slug uniqueness check, persists
  - `delete()` — removes filesystem directory + DB record
  - `markQueued()` — status transition + dispatches `SyncLibraryMessage`
  - `getAbsolutePath()` — resolves `data/libraries/<path>` absolute path

#### VeraCli refactored to pure CLI wrapper
- **`src/Vera/VeraCli.php`** — Removed `getRepoPath()` and slug-based path logic. Now receives resolved absolute paths:
  - `cloneRepository(string $absolutePath, string $gitUrl, string $branch)`
  - `indexLibrary(string $absolutePath, VeraIndexingConfig $config)` — passes exclude patterns, `--no-ignore`, `--no-default-excludes` flags
  - `searchLibrary(string $absolutePath, string $query, array $filters)`
- **`src/Vera/VeraCliException.php`** — Updated factory methods to accept paths instead of slugs.

#### EasyAdmin CRUD
- **`src/Controller/Admin/LibraryCrudController.php`** — Full CRUD at `/admin/libraries`:
  - Index: list with search by name/slug/gitUrl/branch, status badges, last synced/indexed timestamps
  - Detail: all fields + read-only vera config preview
  - New/Edit: form with virtual unmapped fields for `excludePatterns` (textarea), `noIgnore` (checkbox), `noDefaultExcludes` (checkbox). Assembled into `VeraIndexingConfig` VO in `persistEntity()`/`updateEntity()`.
  - Sync Now: custom action dispatches `SyncLibraryMessage` via `LibraryManager::markQueued()`
  - Delete: delegates to `LibraryManager::delete()` (filesystem + DB cleanup)
- **`src/Controller/Admin/DashboardController.php`** — Added Libraries menu item in sidebar.

#### Config
- **`config/services.yaml`** — Added `LibraryManager` service config with `$projectDir` injection.

### Tests added

**Unit tests (48 tests, 79 assertions):**
- **`tests/Unit/Entity/LibraryStatusTransitionTest.php`** — 21 tests covering:
  - All 5 allowed transitions (draft→queued, queued→indexing, indexing→ready, indexing→failed, failed→queued)
  - 8 disallowed transitions (draft→indexing, draft→ready, draft→failed, queued→ready, queued→failed, ready→queued, ready→indexing, indexing→queued, failed→indexing)
  - Domain behavior: error truncation (2000 chars), error clearing on retry/success, touch updates timestamps, path immutability
- **`tests/Unit/Entity/LibraryValidationTest.php`** — 11 tests covering:
  - gitUrl: accepts valid HTTPS + `.git` suffix, rejects empty/http/SSH/non-GitHub
  - branch: accepts `main`, rejects empty
  - name/slug/description: reject empty
- **`tests/Unit/Vera/VeraIndexingConfigTest.php`** — 6 tests covering defaults, fromArray full/partial/empty, round-trip, readonly class
- **`tests/Unit/Service/LibraryManagerTest.php`** — 11 tests covering:
  - `deriveName()`: main branch, non-main branch, `.git` suffix
  - `generateSlug()`, `computePath()`
  - `create()`: auto-derives fields, preserves user overrides, rejects duplicate path
  - `markQueued()`: dispatches message, transitions status
  - `getAbsolutePath()`

**Application tests (9 tests):**
- **`tests/Application/Admin/LibraryCrudTest.php`** — Tests for index/create/detail pages, list content, auth requirement, Sync Now action (draft→queued, failed→queued), search by name

### Verified
- `castor dev:console "lint:container"` → container lints clean
- `castor dev:console "doctrine:schema:validate"` → mapping OK, migration applied
- `castor dev:phpstan` → only pre-existing shipmonk dead-code + minor style warnings
- `castor dev:cs-fix` → clean, 0 files fixed
- `castor dev:test` → **68 tests, 127 assertions, 0 errors, 0 failures**
- Browser verified: CRUD works end-to-end, VeraConfig shows JSON on detail page

### Key decisions made

| Decision | Choice | Why |
|---|---|---|
| Entity field access | Getters/setters | Property hooks confused Doctrine's schema comparator ("no column slug on table libraries") |
| Path immutability | `initializePath()` throws if already set | Prevents accidental path change after creation |
| Vera config storage | JSON column + VO with getter/setter | Clean separation between DB representation and domain logic |
| Virtual form fields | Unmapped EA fields + `applyVeraConfigFromForm()` | EasyAdmin can't auto-bind VO to form; manual assembly in lifecycle hooks |
| Slug field | Plain TextField instead of SlugField | Symfony slugger strips `/` → `symfonysymfony-docs` instead of `symfony-symfony-docs`; manager generates correct slug on create |
| Unique path check | Reject on create if any existing library has same path | Simpler than allowing duplicate for same entity |
| Test DB setup | `cache_clear` + `test_db_prepare` castor tasks | Stale test container cache caused kernel boot to hang; test DB needed fresh migrations each run |
| DAMA config | `enable_static_meta_data_cache` (not `meta_cache`) | Config key naming in v8.x |

### Next stage

**Stage 5** — TBD

## Stage 4 — Ingestion & Indexing Pipeline ✅

**Status:** Complete
**Date:** 2026-04-28

### What was done

#### SyncLibraryMessageHandler
- **`src/MessageHandler/SyncLibraryMessageHandler.php`** — `#[AsMessageHandler]` with linear pipeline:
  1. Load Library by ID (throw `UnrecoverableMessageHandlingException` if not found)
  2. Concurrent sync guard: skip if status !== Queued
  3. `syncStarted()` → flush (Queued → Indexing)
  4. Publish Mercure: `{libraryId, status: "indexing"}`
  5. `prepareCloneDirectory()` (nuke + ensure parent dirs)
  6. `cloneRepository()` via VeraCli
  7. `indexLibrary()` via VeraCli
  8. `syncSucceeded()` → flush (Indexing → Ready)
  9. Publish Mercure: `{libraryId, status: "ready"}`
  10. Catch: `syncFailed()` → flush → publish → throw `UnrecoverableMessageHandlingException`

#### VeraCli simplified
- **`src/Vera/VeraCli.php`** — Removed `alreadyCloned` guard and `mkdir` from `cloneRepository()`. Now a pure CLI wrapper — no filesystem awareness.
- **`src/Vera/VeraCliException.php`** — Removed `alreadyCloned()` factory method.

#### LibraryManager enhancements
- **`src/Service/LibraryManager.php`** — Added `prepareCloneDirectory()` (nuke existing + ensure parent dirs). `create()` now auto-calls `markQueued()` after persist, so creating a library immediately dispatches `SyncLibraryMessage`.

#### LibraryStatus enum
- **`src/Entity/LibraryStatus.php`** — Added `isQueued()` helper for the concurrent sync guard.

#### Messenger routing
- **`config/packages/messenger.yaml`** — Added `App\Message\SyncLibraryMessage: async` routing.
- **`config/packages/test/messenger.yaml`** — Override async transport with `test://` for test environment.

#### Mercure real-time updates
- **`src/MessageHandler/SyncLibraryMessageHandler.php`** — Publishes JSON to `libraries` topic after each status transition.
- **`assets/controllers/library-status_controller.js`** — Lazy-loaded Stimulus controller that subscribes to Mercure `libraries` topic and updates status badges in-place on the EA index page.
- **`templates/admin/library/index.html.twig`** — Custom EA index template wrapping the table with `data-controller="library-status"` + `data-library-id` on each row.
- **`templates/admin/library/field/status.html.twig`** — Custom status field template with `data-status-badge` attribute for Stimulus targeting.
- **`src/Controller/Admin/LibraryCrudController.php`** — Added custom template overrides for index page and status field.
- **`config/packages/twig.yaml`** — Exposed `mercure_public_url` as Twig global.

#### Test infrastructure
- **`config/services_test.yaml`** — Replaces `mercure.hub.default` with `MockHub` in test environment via `NullPublisherFactory`.
- **`tests/Mercure/NullPublisherFactory.php`** — Creates a `MockHub` that swallows all publishes.
- **`zenstruck/messenger-test`** installed for `test://` transport and `InteractsWithMessenger` trait.

### Tests added

**E2E tests (4 tests, using `zenstruck/messenger-test` with real git clone + vera index):**
- **`tests/Application/Admin/SyncLibraryTest.php`** — 4 tests covering:
  - **Happy path** — create library → assert message dispatched → process transport → library reaches `Ready` → repo exists on disk → vera indexed
  - **Failure path** — invalid git URL → process → library reaches `Failed` → `lastError` populated with git error
  - **Auto-dispatch on create** — `LibraryManager::create()` → assert `SyncLibraryMessage` on queue
  - **Concurrent guard** — library already in `Indexing` status → process message → handler returns silently → no status change

**Unit test updates:**
- **`tests/Unit/Service/LibraryManagerTest.php`** — Updated `testCreateSetsComputedFields` and `testCreatePreservesUserOverrides` to account for `create()` now calling `markQueued()` (2 flushes + dispatch).

### Verified
- `castor dev:console "lint:container"` → container lints clean (both dev and test env)
- `castor dev:phpstan` → only pre-existing warnings (no new errors)
- `castor dev:cs-fix` → 1 file auto-fixed
- `castor dev:test` → **72 tests, 154 assertions, 0 errors, 0 failures** (11 pre-existing PHPUnit notices from deprecations)
- E2E: real `git clone` of `zenstruck/messenger-test` (1.x branch) + `vera index` passes inside Docker
- Mercure MockHub verified: no connection errors in test environment

### Key decisions made

| Decision | Choice | Why |
|---|---|---|
| Message transport | async (Doctrine) | Long-running git/vera must not block web process |
| Re-sync strategy | Delete & re-clone | Simpler than git pull; avoids dirty state edge cases |
| Cleanup location | `LibraryManager::prepareCloneDirectory()` | VeraCli stays a pure CLI wrapper |
| Auto-dispatch on create | Yes — `create()` calls `markQueued()` after flush | Admin adds a library → sync starts immediately |
| Handler structure | Single `__invoke()` method | Linear pipeline, no reuse case for extracted methods |
| Flush strategy | Flush after every status transition | Accurate observability; admin sees `Indexing` while sync runs |
| Failure handling | `UnrecoverableMessageHandlingException` | No retries; admin retries manually via "Sync now" |
| Mercure in tests | `MockHub` via `NullPublisherFactory` in `services_test.yaml` | No real Mercure needed in tests; publish is infrastructure detail |
| Test transport | `test://` via `zenstruck/messenger-test` | Allows `process()`, `queue()` assertions |
| Test fixture repo | `zenstruck/messenger-test` (1.x branch) | Small, public GitHub repo; default branch is 1.x |
| Concurrent sync guard | `isQueued()` check at handler start | Prevents double-click / stale message race conditions |
| Frontend | Lazy-loaded Stimulus controller | Zero overhead on non-admin pages; only loads on library index |
| Twig mercure URL | `mercure_public_url` as Twig global | Needed for Stimulus controller `hubUrl` value |

### Files created/modified

**New files (8):**
1. `src/MessageHandler/SyncLibraryMessageHandler.php`
2. `assets/controllers/library-status_controller.js`
3. `config/packages/test/messenger.yaml`
4. `config/services_test.yaml`
5. `templates/admin/library/index.html.twig`
6. `templates/admin/library/field/status.html.twig`
7. `tests/Application/Admin/SyncLibraryTest.php`
8. `tests/Mercure/NullPublisherFactory.php`

**Modified files (7):**
1. `src/Vera/VeraCli.php` — removed `alreadyCloned` guard + mkdir
2. `src/Vera/VeraCliException.php` — removed `alreadyCloned()` factory
3. `src/Service/LibraryManager.php` — added `prepareCloneDirectory()`, auto-dispatch in `create()`
4. `src/Entity/LibraryStatus.php` — added `isQueued()`
5. `src/Controller/Admin/LibraryCrudController.php` — custom templates for index + status field
6. `config/packages/messenger.yaml` — added SyncLibraryMessage routing
7. `config/packages/twig.yaml` — exposed `mercure_public_url` global

### Next stage

TBD
