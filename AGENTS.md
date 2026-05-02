# symfony-web-template

This repository is a reusable Symfony UX template.

## Which tool to use

- **Pure JS behavior, no server round-trip** -- use the `stimulus` skill
- **Navigation, partial page updates** -- use the `turbo` skill
- **Reusable static UI component** -- use the `twig-component` skill
- **Reactive component that re-renders on user input** -- use the `live-component` skill
- **Not sure which one fits** -- use the `symfony-ux` skill
- **Browser automation / UI testing** -- use subagent `browser`, add to task to use `--headed` mode, http://localhost:8080 our path, admin@test.com / admin are credentials.
- **Infrastructure / Docker / project operations** -- use Castor tasks (`castor ...`) and the `castor` skill
- **Database inspection, log search, profiler, server info** -- use Mate Castor tasks (`mate-database:*`, `mate-monolog:*`, `mate-symfony:*`, `mate-server:*`); see "Mate tools" below
- **Creating or updating Castor task definitions** (`castor.php`, `.castor/*.php`) -- read and follow the `castor` skill first

## Operations hierarchy (strict)

For project operations, always use this order:

1. **Mate Castor task first** (`castor mate-<area>:<task>`) for operations Mate covers (database, logs, profiler, server info)
2. **Castor task second** (`castor dev:*`, `castor prod:*`) for everything else
3. **Raw command last** (`docker compose ...`, etc.) only when no Castor task exists

Never jump directly to raw Docker/CLI commands when a Castor task or Mate task exists.

Examples:

- Database schema / queries -> `castor mate-database:database-schema`, `castor mate-database:database-query`
- Log inspection -> `castor mate-monolog:monolog-tail`, `castor mate-monolog:monolog-search`
- Profiler -> `castor mate-symfony:symfony-profiler-list`, `castor mate-symfony:symfony-profiler-get`
- Server info -> `castor mate-server:info`
- Composer install/update/require -> `castor dev:composer-install` / `castor dev:composer "..."`
- PHPUnit / PHPStan / CS Fixer -> `castor dev:test` / `castor dev:phpstan` / `castor dev:cs-fix`
- Docker lifecycle -> `castor dev:*` / `castor prod:*`
- AI index tooling -> `castor dev:ai-index "setup"`, `castor dev:ai-index "wiring:export"`, `castor dev:ai-index "generate --changed"`
- After adding or upgrading Mate extensions -> `castor dev:mate-generate-castor`

## Key rules

- **Never use UUIDs or ULIDs for primary keys or any other field unless explicitly specified.** Always use auto-increment integers (`#[ORM\GeneratedValue]`) unless the spec specifically calls for UUIDs/ULIDs.
- **Use PHP 8.4 property hooks / asymmetric visibility in Doctrine entities.** Replace trivial getter/setter pairs with `public private(set)` (read-only externally) or `public` (read-write) properties. Keep only methods that have real logic (e.g., interface contracts like `getRoles()` that add defaults) or behavior methods (e.g., `touch()`). Doctrine hydrates via reflection so `private(set)` is safe. Use property access (`$user->email`) instead of method calls (`$user->getEmail()`) in all project code.

- Always render `{{ attributes }}` on LiveComponent root elements.
- Use EasyAdmin's built-in `@EasyAdmin/page/login.html.twig` for login pages — do not create custom login templates when EasyAdmin is installed.
- Prefer HTML Twig component syntax (`<twig:Alert />`).
- Use `data-model="debounce(300)|field"` for text fields in LiveComponents.
- Stimulus controllers must clean up listeners/observers in `disconnect()`.
- Turbo Frame IDs must match between page and response.
- Use Turbo Streams for multi-region updates; Frames for single-region updates.
- Prefer injecting `ClockInterface` for time-sensitive logic instead of calling system time directly.
- Prefer Tailwind utility classes over adding custom CSS rules.
- Add custom CSS only when utilities are not enough, and keep it in `assets/styles/app.css`.
- Keep tests deterministic: prefer static assertions and fixed inputs (avoid time/random/network dependent assertions).
- In PHPUnit tests, call assertion helpers statically (`self::assertSame()`, `self::assertTrue()`, etc.) — never via `$this->assert*()`.
- Prefer **application tests** (`WebTestCase`) over unit tests. Use unit tests only when needed (e.g., validation rules, pure domain logic).
- Use `WebTestCase` for HTTP behavior and assert response status + key page content.
- For infrastructure operations, use Castor tasks (`castor ...`); when adding or changing those tasks, follow the `castor` skill.
- Enforce command selection hierarchy: Mate Castor task -> Castor task -> raw command (raw only as fallback).
- For Mate operations not covered by a generated Castor task, fall back to `mate/mate-tool-call.sh <tool-name> '<json-input>'`.
- Never call `docker compose exec ... vendor/bin/mate` directly.
- Never run Composer or PHP on the host for project operations.
- **Never run `asset-map:compile` in dev.** It is a production-only command that writes stale static files to `public/assets/`, which shadow AssetMapper's dynamic serving and hide all CSS/JS changes until you delete them. In dev, AssetMapper serves assets live — no compile step needed.
- **Never `rm -rf public/` subdirectories blindly.** Only `public/assets/` is safe to delete (it's gitignored compiled output from `asset-map:compile`). `public/bundles/` (symlinked by `assets:install`), `public/css/` (may contain committed source files like EasyAdmin overrides), and other `public/` entries are source files — deleting them breaks the app. When in doubt, check git status before deleting.

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

Mate tools are Symfony AI Mate extensions exposed as Castor tasks. **Always prefer `castor mate-<area>:<task>`** over raw queries for operations they cover.

**Available tasks:**

| Area | Tasks | Use for |
|------|-------|--------|
| `mate-database` | `database-schema`, `database-query` | Inspect tables/columns/indexes, run read-only SQL |
| `mate-monolog` | `monolog-tail`, `monolog-search`, `monolog-list-files`, `monolog-list-channels`, `monolog-context-search` | Tail logs, search entries by text/regex/context |
| `mate-symfony` | `symfony-services`, `symfony-profiler-list`, `symfony-profiler-get` | Container service lookup, profiler inspection |
| `mate-server` | `info` | PHP version, OS, loaded extensions |
| `mate-tools` | `tools:list`, `tools:inspect` | Discover available Mate tools and their schemas |

For full details and advanced usage, load the `mate-tools` skill.

## Suggested defaults for new features

- Start with Twig + UX (`stimulus`/`turbo`) before adding extra JS tooling.
- Keep pages server-rendered by default and prefer Turbo/Hotwire for navigation and partial updates.
- Add Live Components only for interactive stateful UI that cannot be handled cleanly with Turbo + Stimulus.
- Add at least one happy-path application test for each new route.

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
