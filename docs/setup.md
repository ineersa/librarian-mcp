# Development setup

Librarian runs on FrankenPHP with Symfony worker mode, built-in Mercure, and shared PostgreSQL + pgvector.

## Stack overview

- PHP runtime: FrankenPHP (`dunglas/frankenphp`) with PHP 8.5 (`pdo_pgsql`, `pdo_sqlite`)
- App mode: Symfony worker mode via `docker/frankenphp/worker.Caddyfile`
- Realtime: Mercure hub built into FrankenPHP/Caddy
- Database / store: shared PostgreSQL + pgvector (`DATABASE_URL`) for Doctrine metadata and official Symfony AI Postgres store tables
- Postgres image: `pgvector/pgvector:pg17-bookworm` (digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`)
- Compose files:
  - Base: `compose.yaml`
  - Dev overrides: `compose.override.yaml`
  - Browser overrides: `compose.browser.yaml` (isolated Playwright stack)
  - Production-like overrides: `compose.prod.yaml`
  - Optional ignored `compose.sources.yaml` (dev/prod only via Castor) for extra read-only source mounts

## Framework note

Owner-approved on 2026-11-12: proceed with **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) using package-scoped stability allowances. See [framework upgrade notes](framework-upgrade.md) for locked dependencies.

Selected packages: MCP 0.15 / sdk 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, UX Stimulus/Turbo 3.7, official Symfony AI Postgres store 0.15.0. The tooling runtime is pinned FrankenPHP 1.13.5 / PHP 8.5. A repository-shared Caddy proxy provides checkout-specific `*.librarian.localhost:8082` development and browser URLs; PostgreSQL stays on private checkout networks.

Plans 03/04 product path: local project registration, read-only Hatfield source discovery, and Librarian-owned hybrid indexing via the official Symfony AI Postgres store. See [project-sources.md](project-sources.md) and [indexing-design.md](indexing-design.md). This supersedes the historical Git/Vera library workflow. Validation status: [indexing-validation.md](indexing-validation.md).

## First-time local setup

1. Bootstrap Castor tooling (see [TESTING.md](../TESTING.md) if `vendor/bin/castor` is missing):

   ```bash
   vendor/bin/castor tooling:prepare
   ```

2. Configure project/source context (typical local values):

   Put application settings such as `CODERANK_*`, `BGE_*` and `INDEX_INTERVAL` in `.env.local` or `.env.dev.local`. Symfony loads these for both web and worker; development Compose does not inject tracked model defaults over them. Castor supplies checkout-owned database credentials explicitly.

   - `PROJECTS_HOST_PATH` (default `.`) mounted read-only as `/projects`
   - `HATFIELD_USER_HOME` (required)
   - `HATFIELD_APP_ROOT` when settings use `%kernel.project_dir%`
   - optional `HATFIELD_PATH_MAPPINGS` JSON longest-prefix mappings
   - `DATABASE_URL`
   - `CODERANK_URL` / `CODERANK_MODEL` / `CODERANK_ARTIFACT`
   - `BGE_URL` / `BGE_MODEL`
   - `INDEX_INTERVAL` / `LOCK_DSN`
   - optional ignored `compose.sources.yaml` for additional read-only user/global/custom mounts (dev/prod via Castor; never imported by the browser stack)

3. Start the development stack and Messenger consumer:

   ```bash
   vendor/bin/castor dev:stack:up
   ```

   Default `app:console` executes inside the running development `php` service. Read the printed `*.librarian.localhost:8082` URL; use `dev:stack:status` to rediscover it later. The worker consumes `async` and `scheduler_default`.

4. Migrate the development database and create a local admin when needed:

   ```bash
   vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
   vendor/bin/castor app:console -- app:user:create-admin admin@example.com --password='change-me-now'
   ```

5. Optional while editing CSS/templates:

   ```bash
   vendor/bin/castor app:console -- tailwind:build --watch
   ```

6. Open the printed development / browser URL from the stack status task (`LIBRARIAN_BROWSER_URL` for Playwright). Caddy binds loopback on port 8082, leaving finance's port 80 untouched. The proxy and route registry are shared across this repository's worktrees; app/worker/PostgreSQL remain checkout-scoped.

The proxy port is repository-wide, not checkout-local. For override/conflict behavior and explicit proxy recreation, follow the [Castor proxy instructions](castor.md#isolated-browser-stack). Production retains its existing `APP_HTTP_BIND` / `APP_HTTP_PORT` configuration. Prefer stack status output over inventing hostnames. PostgreSQL publishes no host ports, including for the native-network browser harness.

If Tailwind binary download fails resolving `github.com`, set Docker DNS overrides in `.env.local`:

```bash
DOCKER_DNS_PRIMARY=1.1.1.1
DOCKER_DNS_SECONDARY=8.8.8.8
```

## Isolated browser stack

For Playwright verification:

```bash
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

Use the printed `*.browser.librarian.localhost:8082` URL for host exploration. The browser database is `librarian_browser` in the checkout-scoped Postgres Docker volume. The browser stack maps synthetic `/project-sources` fixtures, keeps Postgres private, and never imports operator `compose.sources.yaml` overlays. Details: [TESTING.md](../TESTING.md).

## Production-like local run

```bash
vendor/bin/castor list prod
```

Follow [server-deployment.md](server-deployment.md) for env files, migrations, and reverse-proxy notes. Existing production project names, ports, and data mounts are preserved where possible.
