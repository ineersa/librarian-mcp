# Testing

## Scope and approval policy

Keep the suite small: **one happy-path scenario per implemented view or action**. A single CRUD journey may cover its related list, create, detail, edit, and delete operations. Do not duplicate those operations in separate scenarios or hide extra cases in loops and data providers.

**Ask the owner for explicit permission before adding or running special-case tests.** This includes negative paths, boundary values, security matrices, fault injection, concurrency, recovery, and layout permutations. Fixing a bug does not automatically authorize another regression scenario. Database-reset / purge tests remain excluded.

This policy does not remove application authorization, validation, admin auth, or source read-only requirements. Do not claim that happy-path coverage proves those boundaries.

Application behavior is verified through **real Playwright browsers**. PHPUnit is the browser harness, not a separate unit, kernel, service, or API-only testing layer. Keep the generic Castor tasks; do not add scenario-specific runners, orchestration, cross-run manifests, or supplementary datasets.

Baseline happy-path coverage for current admin/MCP surfaces is authorized. Plan 03 also **explicitly authorizes** isolated fixture scenarios for source discovery edge cases (default/custom paths, malformed/missing settings, unavailable mounts, unsupported schema, symlink/external path reporting, duplicate uniqueness, and registration removal leaving sources unchanged). Expanding into unrelated special-case matrices still requires owner permission.

This document does **not** claim that validation has already passed.

## Native-network suite

Browser journeys use Playwright against the isolated stack URL supplied as `LIBRARIAN_BROWSER_URL` (`http://php:8080` inside the private browser network). Stack tasks also print the loopback-published URL for host browser exploration. They do not boot a kernel, intercept HTTP in-process, or roll back database writes for cross-process isolation.

Each scenario opens and closes its own browser/context and signs in through the real login form. Headed launches request a 2560×1440 window with native 1000ms slow motion and `viewport: null`. Headless launches use a 1920×1080 viewport without slow motion.

Saved journeys in `tests/E2E/` (product path after plan 03):

| Scenario | Happy path |
|---|---|
| Homepage | Public landing / login entry |
| Authentication | Login and logout (dashboard title: Projects dashboard) |
| Users | Administrator create, list, edit, verify login, detail, delete only its own user |
| Projects | Register via directory picker, wait for async discovery/health, edit/rescan, detail, remove only its registration |
| MCP HTTP | Optional token path: regenerate the isolated admin's token through the UI when used, then initialize / initialized / tools-list over real HTTP (`tools/list` empty until plan 05) |

Plan 03-authorized fixture journeys exercise synthetic `/project-sources` trees for discovery/uniqueness/removal contracts. They never import operator `compose.sources.yaml` overlays or private owner projects.

CRUD creates uniquely named records and deletes only those registrations through normal confirmation. Do not mutate or delete fixtures owned by other journeys. Do not purge the isolated database to make a scenario pass. Source fixture trees/databases must remain unchanged after registration removal and failed discovery.

The former kernel/unit suite and FakeVera / MockHub / library TOON tool harnesses have been removed. Do not add unit/API-only suites as the primary coverage model. Indexing, hybrid retrieval, and memory tools remain out of scope until later plans.

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

See [framework upgrade notes](docs/framework-upgrade.md) for dependency decisions and [project sources](docs/project-sources.md) for the registration/discovery contract. Do not infer a passing build from this setup guide alone.

## Running tests

`vendor/bin/castor test` prepares the checkout-owned isolated browser stack, applies migrations, ensures the verified isolated admin seed exists, then runs Playwright scenarios headlessly by default. Native PHPUnit arguments go after `--`:

```sh
vendor/bin/castor test -- tests/E2E/AuthenticationTest.php
vendor/bin/castor test -- --list-tests
```

Castor must not hardcode test filenames, method filters, or scenario selection. PHPUnit owns discovery and selection. There is no per-scenario Castor task or custom test coordinator.

Use `test --headed` or `check --headed` only when the owner requests a visible run. Missing display access blocks a requested headed run; it does not justify silently changing modes.

Run `cs:fix`, relevant saved happy-path scenarios, and `qa:static` after code changes. `check` is the combined static + browser gate. The default gate starts the real isolated application but must not exercise owner/production databases or private credentials.

Report actual elapsed time, results, and any gaps. Do not inflate assertion counts or add unauthorized test matrices. Routine validation must stay short.

## Fixtures and isolation

Use a **verified isolated admin seed** for browser journeys (canonical demo operator such as `admin@test.com` / `admin` once seeded into isolated storage). Do not create a supplementary dataset that mutates development data for verification. Do not copy credentials from another checkout into reports.

Native-network SQLite, cache, logs, MCP sessions, Mercure state, and synthetic project fixtures are under `var/browser-live/` (and the browser stack's mapped `/project-sources`). Doctrine migrations prepare the schema without purging it. `tests/Support/prepare_browser.php` accepts only the exact isolated database path with `APP_ENV=test`, verifies Doctrine's resolved path, and prepares only the synthetic admin identity. Cross-process isolation is filesystem/network isolation, not transaction rollback.

Each journey deletes only its own UI-created registrations. Filesystem cleanup is through the application's owned deletion actions, never broad directory cleanup of `data/` or `public/`, and never deletion of source fixture trees.

## Isolated live stack

Use checkout-scoped tasks and their printed URL / `LIBRARIAN_BROWSER_URL`. Do not invent ports, Compose project names, or routing environment variables.

```sh
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

Use `vendor/bin/castor test` for initial migrations and admin preparation. Subsequent targeted runs can use `vendor/bin/castor browser:live:test -- tests/E2E/AuthenticationTest.php`. Do not purge data to make seeding pass.

After changing worker code or streamed templates, run `vendor/bin/castor browser:stack:restart-worker` before the live journey. It leaves the web preview and storage intact.

The upgraded runtime uses a **private network and loopback-published checkout URL**, not a shared multi-checkout development proxy. Always use the URL Castor prints for this checkout. The browser stack never imports operator source overlays.

## Diagnostics and evidence

Main runs saved tests and QA directly through Castor. Read `var/qa/<task>/result.json` first and inspect bounded diagnostics only when needed. Reports are overwritten on the next run of the same task. Do not run the same task concurrently.

`qa:static` includes Composer validation, read-only style checking, PHPStan, and runtime-complete `symfony lsp:check`. Missing tools or incomplete analysis are failures.

Exploratory browser work uses system `playwright-cli` with a unique session and isolated profile. Obtain permission first for special-case investigations. Close owned contexts on success and failure; never use global cleanup commands or the owner's browser profile.

Keep screenshots, traces, profiles, cookies, reports, MCP tokens, OM databases, and session journals private and out of Git. Static checks do not establish runtime behavior. Report only what was actually verified.

## Out of scope for this testing policy

- Finance-project provider cassettes, ChatGPT OAuth, tenant finance databases, and external task boards
- Speculative Symfony AI backends beyond what main installs for the approved upgrade
- Production cutover, data backup, Hatfield semantic removal, and later indexing/MCP-tool plans beyond the authorized plan 03 fixture cases
