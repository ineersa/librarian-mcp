# symfony-web-template

This repository is a reusable Symfony UX template.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates** -- use the `turbo` skill
- **Reusable static UI component** -- use the `twig-component` skill
- **Reactive component that re-renders on user input** -- use the `live-component` skill
- **Not sure which one fits** -- use the `symfony-ux` skill
- **Browser automation / UI testing** -- use Task tool with `subagent_type: "playwright-cli"`
- **Codebase discovery / architecture / broad code search** -- MUST load `vera-mcp` skill and use Vera tools first (`vera_get_stats`, `vera_get_overview`, `vera_search_code`, `vera_regex_search`)
- **Infrastructure / Docker / project operations** -- use Castor tasks (`castor ...`) and the `castor` skill
- **Diagnostics / quality / Composer / PHPStan / PHPUnit operations** -- always load `mate-tools` and use `mate/mate-tool-call.sh` first
- **Creating or updating Castor task definitions** (`castor.php`, `.castor/*.php`) -- read and follow the `castor` skill first

## Vera MCP (mandatory)

- Always load the `vera-mcp` skill before using Vera tools.
- For repository discovery, use this order: `vera_get_stats` -> `vera_get_overview` -> `vera_search_code` -> `vera_regex_search` -> `Read`.
- Exclude deep mode: use iterative targeted queries, not exhaustive/deep exploration.
- Use `vera_search_code` for conceptual/behavior questions; use `vera_regex_search` for exact identifiers and strings.
- Start with narrow filters (`scope`, `path`, `lang`, low `limit`), then widen only if needed.
- Fall back to `Glob`/`Grep` only when Vera cannot produce actionable matches.

## Operations hierarchy (strict)

For project operations, always use this order:

1. **Mate tools first** (if an equivalent Mate command exists)
2. **Castor task second** (`castor ...`) if Mate is not available
3. **Raw command last** (`docker compose ...`, etc.) only when neither Mate nor Castor provides the operation

Never jump directly to raw Docker/CLI commands when the same action exists in Mate or Castor.

Examples:

- Composer install/update/require -> `castor dev:composer-install` / `castor dev:composer "..."` (no Mate composer extension installed)
- PHPUnit / PHPStan -> `castor dev:test` / `castor dev:phpstan` (no Mate phpunit/phpstan extensions installed)
- After adding or upgrading Mate extensions, regenerate Castor tasks: `castor dev:mate-generate-castor` (updates `.castor/mate.generated.php` from `mcp:tools:list --format=json`).
- PHP CS Fixer -> use `castor dev:cs-fix` (no Mate equivalent required by default)
- Docker lifecycle -> `castor dev:*` / `castor prod:*` (not raw `docker compose up/down` unless no task exists)
- AI index tooling -> `castor dev:ai-index "setup"`, `castor dev:ai-index "wiring:export"`, `castor dev:ai-index "generate --changed"`

## Key rules

- Always render `{{ attributes }}` on LiveComponent root elements.
- Prefer HTML Twig component syntax (`<twig:Alert />`).
- Use `data-model="debounce(300)|field"` for text fields in LiveComponents.
- Stimulus controllers must clean up listeners/observers in `disconnect()`.
- Turbo Frame IDs must match between page and response.
- Use Turbo Streams for multi-region updates; Frames for single-region updates.
- Prefer injecting `ClockInterface` for time-sensitive logic instead of calling system time directly.
- Prefer Tailwind utility classes over adding custom CSS rules.
- Add custom CSS only when utilities are not enough, and keep it in `assets/styles/app.css`.
- Keep tests deterministic: prefer static assertions and fixed inputs (avoid time/random/network dependent assertions).
- Use `WebTestCase` for HTTP behavior and assert response status + key page content.
- For infrastructure operations, use Castor tasks (`castor ...`); when adding or changing those tasks, follow the `castor` skill.
- Enforce command selection hierarchy: Mate -> Castor -> raw commands (raw only as fallback).
- For Composer/PHPStan/PHPUnit, always load `mate-tools` and use `mate/mate-tool-call.sh <tool-name> '<json-input>'`.
- Never call `docker compose exec ... vendor/bin/mate` directly.
- Never run Composer or PHP on the host for project operations.
- For browser verification, always use `playwright-cli` subagent.
- Prefer Mate tools for diagnostics/quality commands when available (`mate-tools` skill + wrapper scripts).

## Docker setup

- Runtime stack: FrankenPHP (PHP 8.5), Symfony worker mode, Mercure, SQLite.
- SQLite file path: `data/app` (`DATABASE_URL=sqlite:///%kernel.project_dir%/data/app`).
- Keep `data/.gitignore` as `*` and `!.gitignore`.
- Dev compose: `compose.yaml` + `compose.override.yaml`.
- Prod-like compose: `compose.yaml` + `compose.prod.yaml`.

## LLM mode

- For LLM-driven Castor execution, set `LLM_MODE=true`.
- In LLM mode, Castor tasks must stay token-efficient (no progress bars / fluff output).
- Reports are written to `var/reports/` (`phpstan.json`, `phpstan.log`, `php-cs-fixer.json`, `php-cs-fixer.log`, `phpunit.junit.xml`, `phpunit.log`).

## Castor flow

- First-time setup: `castor dev:setup`, then `castor dev:bootstrap` (and `castor dev:console "doctrine:migrations:migrate --no-interaction"` if Doctrine is used).
- Background workers: `castor dev:messenger-consume`.
- Local lifecycle: `castor dev:up`, `castor dev:down`, `castor dev:restart`, `castor dev:ps`.
- Prod-like lifecycle: `castor prod:up`, `castor prod:down`, `castor prod:restart`, `castor prod:ps`.

## Mate tools
For Mate tools load `mate-tools` SKILL.
They include tools for: database, monolog and logs, profiler, server info.

## Template placeholders

- Replace `{{PROJECT_PATH}}` in `.vscode/*.sh` wrappers.
- Replace `{{APP_DOMAIN}}` in `.env.prod.local.dist` for production setup.

## Suggested defaults for new features

- Start with Twig + UX (`stimulus`/`turbo`) before adding extra JS tooling.
- Keep pages server-rendered by default and prefer Turbo/Hotwire for navigation and partial updates.
- Add Live Components only for interactive stateful UI that cannot be handled cleanly with Turbo + Stimulus.
- Add at least one happy-path application test for each new route.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate Summary:
- Role: MCP-powered, project-aware coding guidance and tools.
- Required action: Read and follow `mate/AGENT_INSTRUCTIONS.md` before taking any action in this project, and prefer MCP tools over raw CLI commands whenever possible.
- Installed extensions: ineersa/database-extension, symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

<!-- ai-index:begin -->
## AI Documentation Index

This repository uses AI index files for fast code navigation.

- Class docs: `src/**/docs/*.toon`
- Namespace indexes: `src/**/ai-index.toon`

Generated index files are managed via `vendor/bin/ai-index`.

### IDE indexing rule

- JetBrains IDEs must **not** index `*.toon` files (exclude them from indexing).

### Recommended commands

- `vendor/bin/ai-index setup`
- `vendor/bin/ai-index wiring:export`
- `vendor/bin/ai-index generate --changed`
- `vendor/bin/ai-index generate --all --force`

For curated description updates, use `.agents/index-maintainer.md`.
<!-- ai-index:end -->
