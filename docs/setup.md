# Development setup

Librarian runs on FrankenPHP with Symfony worker mode, built-in Mercure, and SQLite.

## Stack overview

- PHP runtime: FrankenPHP (`dunglas/frankenphp`) with PHP 8.5
- App mode: Symfony worker mode via `docker/frankenphp/worker.Caddyfile`
- Realtime: Mercure hub built into FrankenPHP/Caddy
- Database: SQLite at `data/app`
- Vector store: private Chroma service (image tag 1.0.21; `/version` may report 1.0.0; no public host port)
- Compose files:
  - Base: `compose.yaml`
  - Dev overrides: `compose.override.yaml`
  - Browser overrides: `compose.browser.yaml` (isolated Playwright stack)
  - Production-like overrides: `compose.prod.yaml`
  - Optional ignored `compose.sources.yaml` (dev/prod only via Castor) for extra read-only source mounts

## Framework note

Owner-approved on 2026-11-12: proceed with **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) using package-scoped stability allowances. See [framework upgrade notes](framework-upgrade.md) for locked dependencies.

Selected packages: MCP 0.15 / sdk 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, UX Stimulus/Turbo 3.7. The tooling runtime is pinned FrankenPHP 1.13.5 / PHP 8.5; development and browsers use private networks with loopback-published URLs, not a shared proxy.

Plans 03/04 product path: local project registration, read-only Hatfield source discovery, and Librarian-owned hybrid indexing. See [project-sources.md](project-sources.md) and [indexing-design.md](indexing-design.md). This supersedes the historical Git/Vera library workflow. Plan 04 acceptance evidence is still incomplete.

## First-time local setup

1. Bootstrap Castor tooling (see [TESTING.md](../TESTING.md) if `vendor/bin/castor` is missing):

   ```bash
   vendor/bin/castor tooling:prepare
   ```

2. Configure project/source context (typical local values):

   - `PROJECTS_HOST_PATH` (default `.`) mounted read-only as `/projects`
   - `PROJECT_BROWSE_ROOT` picker start path inside the service
   - `HATFIELD_USER_HOME` (required)
   - `HATFIELD_APP_ROOT` when settings use `%kernel.project_dir%`
   - optional `HATFIELD_PATH_MAPPINGS` JSON longest-prefix mappings
   - `CODERANK_URL` / `CODERANK_MODEL` / `CODERANK_ARTIFACT`
   - `BGE_URL` / `BGE_MODEL`
   - `CHROMA_HOST` / `CHROMA_PORT`
   - `INDEX_STORAGE_DIR` / `INDEX_INTERVAL` / `LOCK_DSN`
   - optional ignored `compose.sources.yaml` for additional read-only user/global/custom mounts (dev/prod via Castor; never imported by the browser stack)

3. Migrate the development database:

   ```bash
   vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
   ```

4. Create a local admin user when needed (confirm command via console list):

   ```bash
   vendor/bin/castor app:console -- app:user:create-admin admin@example.com --password='change-me-now'
   ```

5. Start the development stack and Messenger consumer with `vendor/bin/castor dev:stack:up` and read its loopback URL. The worker consumes `async` and `scheduler_default`. Use `dev:stack:status` to rediscover the URL later.

6. Optional while editing CSS/templates:

   ```bash
   vendor/bin/castor app:console -- tailwind:build --watch
   ```

7. Open the printed development / browser URL from the stack status task (`LIBRARIAN_BROWSER_URL` for Playwright). The upgraded runtime keeps app/Chroma private and prints a loopback checkout URL (host networking on Linux) — not a shared multi-checkout proxy.

Development/browser ports are allocated and persisted per checkout by Castor. Production retains its existing `APP_HTTP_BIND` / `APP_HTTP_PORT` configuration. Prefer stack status output over inventing ports.

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

Use the printed loopback URL for host exploration. The browser stack maps synthetic `/project-sources` fixtures and derived indexes under `var/browser-live/indexes`, keeps Chroma private, and never imports operator `compose.sources.yaml` overlays. Details: [TESTING.md](../TESTING.md).

## Production-like local run

```bash
vendor/bin/castor list prod
```

Follow [server-deployment.md](server-deployment.md) for env files, migrations, and reverse-proxy notes. Existing production project names, ports, and data mounts are preserved.
