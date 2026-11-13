# Testing

## Scope and approval policy

Keep the suite small: **one happy-path journey per feature**. A single CRUD journey covers its related list, create, detail, edit, and delete operations. **Projects has exactly one journey**, including registration, source discovery, Index now, search preview, ordinary harness-owned fixture content/revision update, edit/rescan, and removal. Do not add separate discovery, error, uniqueness, or indexing matrices, or hide extra cases in loops and data providers.

**Ask the owner for explicit permission before adding or running special-case tests.** This includes negative paths, boundary values, security matrices, fault injection, concurrency, recovery, and layout permutations. Fixing a bug does not automatically authorize another regression scenario. Database-reset / purge tests remain excluded.

This policy does not remove application authorization, validation, admin auth, or source read-only requirements. Do not claim that happy-path coverage proves those boundaries.

Application behavior is verified through **real Playwright browsers**. PHPUnit is the browser harness, not a separate unit, kernel, service, or API-only testing layer. Keep the generic Castor tasks; do not add scenario-specific runners, orchestration, cross-run manifests, or supplementary datasets.

Baseline happy-path coverage for current admin/MCP surfaces is authorized. The owner's one-journey rule supersedes the earlier plan 03 source-case matrix; do not restore it.

This document does **not** claim that validation has already passed.

## Native-network suite

Browser journeys use Playwright against the isolated stack URL supplied as `LIBRARIAN_BROWSER_URL`. On Linux the harness prints/uses a loopback URL via host networking; app and Postgres remain private (no public DB port, no shared proxy). Only fixture preparation boots a test kernel; browser requests are real HTTP, not intercepted in-process. PHPUnit cannot roll back web/worker writes for cross-process isolation.

Each scenario opens and closes its own browser/context and signs in through the real login form. Headed launches request a 2560×1440 window with native 1000ms slow motion and `viewport: null`. Headless launches use a 1920×1080 viewport without slow motion.

Saved journeys in `tests/E2E/` (product path after plan 03):

| Scenario | Happy path |
|---|---|
| Homepage | Public landing / login entry |
| Authentication | Login and logout (dashboard title: Projects dashboard) |
| Users | Administrator create, list, edit, verify login, detail, delete only its own user |
| Projects | Register via directory picker, wait for async discovery/health, Index now + committed index, search a public synthetic observation (no date-window UI), ordinary harness-owned fixture update + reindex/search, edit/rescan, detail, remove only its registration |
| MCP HTTP | Optional token path: regenerate the isolated admin's token through the UI when used, then initialize / initialized / tools-list over real HTTP (`tools/list` empty until plan 05) |

The Projects journey uses only the two synthetic source trees needed for registration and path rebinding during edit. Intentional fixture mutation is allowed only through harness-owned helpers against verified synthetic paths, with restore in `finally` so repeated runs stay idempotent. It never imports operator `compose.sources.yaml` overlays or private owner projects.

CRUD creates uniquely named records and deletes only those registrations through normal confirmation. Do not mutate or delete fixtures owned by other journeys. Do not purge the isolated database to make a scenario pass. After the journey restores any intentional harness mutation, source fixture trees/databases must match the pre-journey hash; registration removal must not change sources.

The former kernel/unit suite and FakeVera / MockHub / library TOON tool harnesses have been removed. Do not add unit/API-only suites as the primary coverage model. Public MCP memory tools remain plan 05. Plan 04 Postgres cutover validation is owned by main and is not claimed by docs alone; see [indexing design](docs/indexing-design.md) and [indexing validation](docs/indexing-validation.md).

## Prepare the tooling runtime

Use Linux amd64, Docker, Docker Compose 2.24.4 or newer, Git, and host PHP 8.5 CLI with PCNTL/POSIX when Castor requires them. Castor owns application runtime selection. Only owner-requested headed runs need display access and host `xauth`.

If `vendor/bin/castor` is missing, bootstrap the locked dependencies once through the development image:

```sh
docker build --target frankenphp_dev -t librarian-mcp-bootstrap .
docker run --rm --user "$(id -u):$(id -g)" --volume "$PWD:/app" --entrypoint composer librarian-mcp-bootstrap install --no-interaction
```

Then use the host Castor entry point:

```sh
vendor/bin/castor tooling:prepare
vendor/bin/castor cs:fix
vendor/bin/castor test
vendor/bin/castor qa:static
```

`tooling:prepare` builds the development tooling image, installs locked dependencies, and prepares Chromium. Private browser/tooling files live under ignored `var/tooling/`. Do not invoke another PHP runner around Castor.

See [framework upgrade notes](docs/framework-upgrade.md), [project sources](docs/project-sources.md), and [indexing design](docs/indexing-design.md). Do not infer a passing build or plan 04 acceptance from this setup guide alone.

## Running tests

`vendor/bin/castor test` prepares the checkout-owned isolated browser stack, recreates only `var/browser-live/application.db` through Doctrine commands, applies migrations, then runs Playwright scenarios headlessly by default. Alice's `RefreshDatabaseTrait` loads the canonical admin fixture before the first journey. Native PHPUnit arguments go after `--`:

```sh
vendor/bin/castor test -- tests/E2E/AuthenticationTest.php
vendor/bin/castor test -- --list-tests
vendor/bin/castor test --headed -- tests/E2E/ProjectCrudTest.php
```

Castor must not hardcode test filenames, method filters, or scenario selection. PHPUnit owns discovery and selection. There is no per-scenario Castor task or custom test coordinator.

Use `test --headed` or `check --headed` only when the owner requests a visible run. Missing display access blocks a requested headed run; it does not justify silently changing modes.

Run `cs:fix`, relevant saved happy-path scenarios, and `qa:static` after code changes. `check` is the combined static + browser gate. The default gate starts the real isolated application but must not exercise owner/production databases or private credentials.

Report actual elapsed time, results, and any gaps. Do not inflate assertion counts or add unauthorized test matrices. Routine validation must stay short.

## Fixtures and isolation

Use the canonical Alice fixture `fixtures/user.yaml` for development and test (`admin@test.com` / `admin`); its password is hashed by `UserPasswordProcessor`. Do not create a supplementary dataset or mutate development data for verification. Do not copy credentials from another checkout into reports.

Native-network Postgres, cache, logs, MCP sessions, Mercure state, and synthetic project fixtures are under `var/browser-live/` (and the browser stack's mapped `/project-sources`). The owner has authorized resetting this checkout's isolated browser database before saved runs. Castor rejects symlinked storage, stops the owned worker, recreates/migrates the isolated browser database with Doctrine commands, and restarts the application/worker. This resets rows and auto-increment sequences. Exact Postgres recreate mechanics are owned by the infrastructure child / main. It does not reset development/production databases or delete source trees, uploads, unrelated volumes, or owner storage.

`LibrarianNetworkBrowserTest` extends `KernelTestCase` and uses Alice's `RefreshDatabaseTrait`. It verifies the exact test database URL and storage before loading fixtures. DAMA's bundle and PHPUnit extension are enabled. Network browser journeys use DAMA's supported `SkipDatabaseRollback` attribute: web and worker writes happen in separate processes and cannot be rolled back by PHPUnit's connection. The trait's initial transaction is committed before Chromium starts so those processes can see Alice's fixture data. Do not claim cross-process transactional rollback. Fresh storage is provided once per saved `test` run, not between each HTTP request.

Each journey deletes only its own UI-created registrations. Filesystem cleanup is through the application's owned deletion actions, never broad directory cleanup of `data/` or `public/`, and never deletion of source fixture trees.

## Isolated live stack

Use checkout-scoped tasks and their printed URL / `LIBRARIAN_BROWSER_URL`. Do not invent ports, Compose project names, or routing environment variables.

```sh
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

Use `vendor/bin/castor test` for a fresh migrated database and canonical fixture preparation. `browser:live:test` intentionally reuses current storage and therefore does not promise restarted IDs; use it only for deliberate live-stack diagnosis. It sets `LIBRARIAN_BROWSER_REUSE_STORAGE=1` so Alice treats the existing database as already populated and neither purges rows nor reloads fixtures. Prepare the stack with `test` first; the selected live journey still performs its normal application writes and cleanup. To watch only Projects with fresh IDs, use `vendor/bin/castor test --headed -- tests/E2E/ProjectCrudTest.php`.

After changing worker code or streamed templates, run `vendor/bin/castor browser:stack:restart-worker` before the live journey. It leaves the web preview and storage intact.

The upgraded runtime uses a **private app/Postgres network** and a printed loopback checkout URL (host networking on Linux), not a shared multi-checkout development proxy. Always use the URL Castor prints for this checkout. The browser stack never imports operator source overlays and does not publish the database.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures.

Exploratory browser work uses system `playwright-cli` with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, MCP tokens, OM databases, and session journals private and out of Git. Static checks do not establish runtime behavior. Report only what was actually verified.

## Out of scope for this testing policy

- Finance-project provider cassettes, ChatGPT OAuth, tenant finance databases, and external task boards
- Speculative Symfony AI backends beyond the approved CodeRank + official Postgres store + local BGE baseline
- Production cutover, data backup, Hatfield semantic removal, and MCP memory-tool plan 05
