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

## Stage 3 — Library Catalog 🔲

Not started.

## Stage 4 — Ingestion & Indexing Pipeline 🔲

Not started.

## Stage 5 — HTTP MCP Server 🔲

Not started.

## Stage 6 — Post-MVP Library Lifecycle 🔲

Not started.

## Stage 7 — Low-Priority Exploration 🔲

Not started.
