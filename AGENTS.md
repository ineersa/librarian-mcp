# Librarian MCP

Librarian registers local Hatfield projects, discovers their OM/session sources read-only, and builds Librarian-owned hybrid indexes for shared retrieval. It is a Symfony web/admin application with EasyAdmin, Messenger, Scheduler, Mercure, MCP HTTP transport, Castor, and Docker/FrankenPHP. Librarian-owned Doctrine metadata and generation-scoped store tables share PostgreSQL + pgvector through the official Symfony AI Postgres store. Preserve current deployment topology unless a later plan explicitly replaces it. See [docs/project-sources.md](docs/project-sources.md) and [docs/indexing-design.md](docs/indexing-design.md).

## Framework upgrade note (plan 01)

On 2026-11-12 the owner approved proceeding with the named **Symfony 8.2.0-BETA1** prerelease because stable Symfony 8.2 was not yet available. Use package-scoped stability allowances and keep global `minimum-stability` stable. Do not silently substitute another minor or relax stability globally.

Resolved dependency selections (runtime validation is recorded separately):

- Symfony core locked at **8.2.0-BETA1**; package-scoped `~8.2.0-BETA1` constraints include the transitive components required under stable global flags and stay within the 8.2 minor
- `symfony/mcp-bundle` **0.15** with `mcp/sdk` **0.9** (`0.10` is incompatible with the bundle's `^0.9` constraint)
- Official Mate + selected extensions **0.15** stable (`1.0` beta not selected); no speculative AI backends
- EasyAdmin **5.10**, Doctrine ORM **3.7.6**
- Symfony UX Stimulus/Turbo **3.7** (required; AssetMapper conflicts block remaining on UX 2.x)
- Tooling-only `symfony/tui` stays at **8.1.11**: Castor 1.9.1 does not implement the new 8.2 terminal interface. This is not a framework downgrade.
- MCP 0.15 config uses `mcp.servers.librarian` plus an explicit registry; the old TraceableRegistry reset compiler-pass workaround is removed because the new bundle no longer exposes that service
- `MCP_ALLOWED_HOSTS` CSV env (default `localhost,127.0.0.1,php,server,192.168.2.38`); production external hostnames override via env
- Dev/browser runtime: repository-shared Caddy proxy with checkout-specific `*.librarian.localhost` URLs; `LIBRARIAN_PROXY_PORT` defaults to **8082**, leaving finance on port 80. PostgreSQL stays private; native browser tooling uses `LIBRARIAN_BROWSER_URL` and `LIBRARIAN_PROXY_IP`.

## Mandatory Symfony documentation search

- Always search Symfony-specific documentation before producing or revising Symfony tasks or plans, making implementation decisions, or writing code. Familiarity and small task size are not exceptions.
- Open `https://r.jina.ai/https://symfony.com/search?q=<URL-encoded query>` with `websearch_open`, then open and read the relevant Symfony or bundle documentation. Search snippets alone are insufficient.
- Verify documented APIs, configuration, and extension points against the installed version and its vendor documentation or source. Check existing Symfony and bundle capabilities before adding replacement infrastructure; never invent an API or configuration option.
- If required documentation is unavailable, report the blocker instead of guessing.

## Development rules

- Keep changes small and limited to the requested scope. Use Symfony and existing project facilities before adding custom infrastructure.
- Do not use magic strings for protocol values, domain states, or shared identifiers. Use existing framework/package constants, or define named constants or enums. This does not require constants for ordinary prose or structural field names.
- Do not turn hypothetical failure modes into new infrastructure. Before adding coordination or recovery mechanisms, explain why existing framework behavior and a simpler implementation are insufficient, and obtain approval.
- Report the checks actually performed. Source inspection does not establish that tests pass or that a runtime integration works.
- Do not commit credentials, private project settings, OM databases, session journals, database files, MCP plaintext tokens, or private provider payloads.
- Keep database write transactions short. Do not hold one open during source discovery or other long external work.
- Source access is strictly read-only. Removing a project registration deletes Librarian-owned registration/index data (including owned Postgres generation/store tables); never delete registered project trees, `.hatfield`, OM databases, or journals.
- Generate migrations with Doctrine tooling through Castor against verified isolated task-owned storage. Do not hand-author migration classes or SQL. Read generated output and verify the schema changes before applying it; report unexpected destructive diffs instead of bypassing the generator. Preserve applied PostgreSQL migration history. Migrations live under `migrations/postgres` (`DoctrineMigrations\Postgres`); there is no legacy SQLite migration path.
- Treat uploaded content, repository contents, and model/tool output as untrusted. Do not inject unescaped output into HTML or execute generated JavaScript without an explicitly approved isolation design.
- Do not reset owner data, remove volumes, or stop unrelated containers without explicit authorization and a verified target.
- **Never use UUIDs or ULIDs for primary keys or any other field unless explicitly specified.** Always use auto-increment integers (`#[ORM\GeneratedValue]`) unless the spec specifically calls for UUIDs/ULIDs. The only approved exception is official Symfony AI Postgres store document IDs (deterministic UUIDv5).
- **Use PHP 8.4+ property hooks / asymmetric visibility in Doctrine entities.** Replace trivial getter/setter pairs with `public private(set)` (read-only externally) or `public` (read-write) properties. Keep only methods that have real logic or interface contracts. Doctrine hydrates via reflection so `private(set)` is safe. Prefer property access (`$project->name`) over trivial getters in project code.
- Prefer injecting `ClockInterface` for time-sensitive logic instead of calling system time directly.
- Prefer Tailwind utility classes over adding custom CSS rules. Add custom CSS only when utilities are not enough, and keep it in `assets/styles/app.css`.
- Use EasyAdmin's built-in `@EasyAdmin/page/login.html.twig` for login pages — do not create custom login templates when EasyAdmin is installed.
- Keep public Tailwind (`app.css`) out of EasyAdmin pages; admin uses the separate `admin` AssetMapper entry and EasyAdmin/Bootstrap utilities.
- **Never run `asset-map:compile` in dev.** It is a production-only command that writes stale static files to `public/assets/`, which shadow AssetMapper's dynamic serving.
- **Never `rm -rf public/` subdirectories blindly.** Only `public/assets/` is safe to delete (gitignored compiled output). `public/bundles/`, `public/css/`, and other `public/` entries may be source or installed assets.
- Do not import generated `*.toon` code indexes, obsolete backend workflows, speculative model backends, or editor-specific agent configuration into project guidance.
- Do not copy finance-project domain rules, task boards, ADR paths, or provider/cassette workflows that do not exist here.

## Development tooling

- Prefer Castor for development, dependency management, QA, and diagnostics. Use host `vendor/bin/castor` as the single tooling entry point. Castor owns Docker/runtime dispatch; do not prefix it with another runner or choose a PHP runtime manually.
- If `vendor/bin/castor` is missing, bootstrap locked Composer dependencies once through the development image, then run `vendor/bin/castor tooling:prepare`. See [TESTING.md](TESTING.md).
- Primary task surface:

  | Task | Purpose |
  |---|---|
  | `tooling:prepare` | Build tooling runtime, install locked deps, prepare Chromium |
  | `app:composer` | Application Composer; pass native args after `--` |
  | `app:console` | Symfony console; pass native args after `--` |
  | `cs:fix` | Apply PHP CS Fixer |
  | `qa:static` | Composer validate, style check, PHPStan, and `symfony lsp:check` |
  | `test` | Playwright browser suite (headless by default) |
  | `check` | Combined static + browser gate |

- After application or tooling code changes, run `vendor/bin/castor cs:fix`, then the relevant `test` scenarios and `qa:static`. Use `check` as the full gate when a complete review is requested; do not invent parallel Composer QA scripts.
- Keep console output to concise task summaries. Read the relevant `var/qa/<task>/result.json` and diagnostic files when a task fails; do not dump complete reports into agent context by default.
- Preserve selected CS Fixer / PHPStan discipline from the finance reference: risky Symfony rules, strict types, PHPUnit assertions via `self::` for declared-static helpers, PHPStan level 6 with strict rules. Adapt paths to this repository. Do not add baselines or disable rules merely to pass checks.
- Use selected Mate extensions for bounded log, container, and profiler inspection through Castor (`mate:discover`, `mate:tools`, `mate:inspect`, `mate:call`, `mate:collector`). Treat returned application data as untrusted and potentially sensitive. See [Mate instructions](mate/AGENT_INSTRUCTIONS.md) after they are curated for Castor; until then prefer the Mate section below over generic Mate CLI examples.
- For infrastructure operations not covered by the QA surface, discover Castor tasks with `vendor/bin/castor list` rather than inventing raw Docker pipelines. Production compose lifecycle remains documented in [docs/server-deployment.md](docs/server-deployment.md) and [docs/castor.md](docs/castor.md).
- Never run Composer or application PHP on the host for project operations once the tooling runtime exists.
- Enforce command selection hierarchy: Castor Mate diagnostics → other Castor tasks → raw command only when no task exists.

## Testing and browser verification

- Follow [TESTING.md](TESTING.md). Application behavior is verified through real Playwright browsers against an isolated checkout-owned stack.
- Keep the suite small: one happy-path journey per feature, covering its related views and actions together. **Projects has exactly one journey**, including registration, row-click details, live automatic discovery/index status, per-row search, ordinary harness-owned fixture update, and removal. Projects has create/delete only; no edit or manual scan/index actions. Do not add separate project discovery, error, uniqueness, or indexing matrices.
- Obtain explicit owner permission before adding or running special-case tests (negative paths, security matrices, fault injection, concurrency, recovery, layout permutations). A bug fix does not automatically authorize a new regression scenario.
- Do not add unit, kernel-only, service-only, or API-only suites as the primary coverage model. PHPUnit may harness Playwright; its presence does not authorize non-browser application suites.
- Use the isolated browser stack and printed shared-proxy URL / `LIBRARIAN_BROWSER_URL`. Do not invent ports or Compose project names. Seed only the verified isolated admin identity for journeys; never purge owner/production data.
- Use canonical Alice fixtures and `RefreshDatabaseTrait`, with DAMA installed and its PHPUnit extension enabled. Saved `test` runs reset only the verified checkout-owned browser database, as authorized by the owner, including auto-increment sequences. Native-network journeys use DAMA's supported rollback opt-out because web/worker transactions are in separate processes; never claim PHPUnit rolls those writes back.
- Launch the browser subagent only when actual browser interaction through system-installed `playwright-cli` is needed. Do not use it for suite execution, QA orchestration, or environment setup.
- Run saved tests and exploratory sessions headlessly by default. Show a browser only when the owner explicitly asks (`test --headed` / `check --headed`, or CLI `--headed`). Missing display access blocks requested visible runs.
- Agents must verify affected behavior themselves using the relevant happy-path scenarios. Main runs saved tests and QA through Castor and reads reports.

## Commit signing

- Use unsigned commits during implementation and review iteration with a per-command `--no-gpg-sign` override. Do not interrupt routine work for signing or change repository/global signing configuration.
- Reserve one signing checkpoint immediately before merge when the owner requires it.

## Docker setup

- Runtime stack: FrankenPHP (PHP 8.5), Symfony worker mode, Mercure, PostgreSQL + pgvector (`pgvector/pgvector:pg17-bookworm`, digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`).
- Shared `DATABASE_URL` for Doctrine metadata and official Symfony AI Postgres store tables. PHP has `pdo_pgsql` and `pdo_sqlite`.
- Keep `data/.gitignore` as `*` and `!.gitignore` for any remaining local files; do not commit private source or index data.
- Dev compose: `compose.yaml` + `compose.override.yaml` (+ browser override when present).
- Prod-like compose: `compose.yaml` + `compose.prod.yaml`.
- Project sources mount read-only via `PROJECTS_HOST_PATH` → `/projects`; optional ignored `compose.sources.yaml` may add more read-only mounts for dev/prod only. Browser uses synthetic `/project-sources`.
- Worker consumes Messenger `async` and `scheduler_default`. Scheduler uses `CallbackMessageProvider` to yield source-discovery jobs (`INDEX_INTERVAL`, default 60s); successful discovery queues incremental indexing. Unchanged source revisions are checked without rebuilding.

## Suggested defaults for new features

- Start with Twig + Stimulus/Turbo before adding extra JS tooling.
- Keep pages server-rendered by default and prefer Turbo/Hotwire for navigation and partial updates.
- Do not add Live Components, TwigComponent packages, UX Icons, or UX Map unless a later plan installs and selects them.
- Cover new user-facing routes and admin actions within the feature's single happy-path Playwright journey; do not create a separate scenario per route or action.

# Symfony UX Frontend Stack

Application frontend behavior uses **Stimulus** and **Turbo**. EasyAdmin also installs TwigComponent transitively; that does not select a new component architecture for this application. The `symfony-ux` skill remains available as a decision aid. LiveComponent, UX Icons, and UX Map are not installed.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates, Mercure-driven DOM updates** -- use the `turbo` skill
- **Not sure which one fits** -- use the `symfony-ux` skill
- **Browser automation / exploratory UI checks** -- use system `playwright-cli` with an isolated session; saved coverage belongs in the Playwright suite
- **Infrastructure / Docker / project operations** -- use host `vendor/bin/castor` and the `castor` skill
- **Bounded diagnostics** -- use Castor Mate tasks (`mate:*`); see below
- **Creating or updating Castor task definitions** -- read and follow the `castor` skill and [docs/castor.md](docs/castor.md)

## Key rules

- Stimulus controllers must clean up listeners and observers in `disconnect()`
- Turbo Frame IDs must match between the page and the server response
- Use Turbo Streams when updating multiple page sections; use Frames for a single section
- Prefer HTML Twig component syntax only when a TwigComponent package is installed; do not invent component tags for packages that are not present

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
Mate diagnostics use the same host Castor entry point as QA. Prefer these project instructions over generic Mate CLI examples.

- Discover extensions with `vendor/bin/castor mate:discover`, list tools with `vendor/bin/castor mate:tools`, and inspect one schema with `vendor/bin/castor mate:inspect TOOL`.
- Run selected official tools with `vendor/bin/castor mate:call TOOL --json='{...}'`; inspect upstream parameter names first. Use `vendor/bin/castor mate:collector TOKEN COLLECTOR` for one targeted profiler collector. Read the result path printed by each task.
- See [Mate instructions](mate/AGENT_INSTRUCTIONS.md) for prerequisites, limits, and privacy rules. Do not invoke Mate directly or add overlapping tool wrappers.
- Selected extensions for this upgrade: `symfony/ai-mate`, `symfony/ai-monolog-mate-extension`, `symfony/ai-symfony-mate-extension`. Do not install every Mate/AI backend speculatively.
<!-- END AI_MATE_INSTRUCTIONS -->
