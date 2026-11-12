# Development setup

Librarian runs on FrankenPHP with Symfony worker mode, built-in Mercure, and SQLite.

## Stack overview

- PHP runtime: FrankenPHP (`dunglas/frankenphp`) with PHP 8.5
- App mode: Symfony worker mode via `docker/frankenphp/worker.Caddyfile`
- Realtime: Mercure hub built into FrankenPHP/Caddy
- Database: SQLite at `data/app`
- Compose files:
  - Base: `compose.yaml`
  - Dev overrides: `compose.override.yaml`
  - Browser overrides: as provided by the runtime child (isolated Playwright stack)
  - Production-like overrides: `compose.prod.yaml`

## Framework note

Owner-approved on 2026-11-12: proceed with **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) using package-scoped stability allowances. Exact pins, container image updates, and validation are owned by main/runtime. Do not claim the upgrade has landed until those children report lockfile and smoke proof.

Reported selections to document against (proof pending on main): MCP 0.15 / sdk 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, UX Stimulus/Turbo 3.7, private-network loopback browser URL (no shared proxy).

## First-time local setup

1. Bootstrap Castor tooling (see [TESTING.md](../TESTING.md) if `vendor/bin/castor` is missing):

   ```bash
   vendor/bin/castor tooling:prepare
   ```

2. Migrate the development database:

   ```bash
   vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
   ```

3. Create a local admin user when needed (confirm command via console list):

   ```bash
   vendor/bin/castor app:console -- app:user:create-admin admin@example.com --password='change-me-now'
   ```

4. Start the development stack and Messenger consumer using the Castor tasks discovered with `vendor/bin/castor list` (runtime child owns exact `dev:stack:*` / messenger task names).

5. Optional while editing CSS/templates:

   ```bash
   vendor/bin/castor app:console -- tailwind:build --watch
   ```

6. Open the printed development / browser URL from the stack status task (`LIBRARIAN_BROWSER_URL` for Playwright). The upgraded runtime uses a private network and loopback-published checkout URL — not a shared multi-checkout proxy.

If compose still exposes host ports for a given environment, overrides may live in `.env.local` (for example `HTTP_PORT` / `HTTPS_PORT` or `APP_HTTP_PORT`). Prefer the URL printed by Castor stack status over inventing ports.

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

Use `LIBRARIAN_BROWSER_URL` from the status output. Details: [TESTING.md](../TESTING.md).

## Production-like local run

```bash
vendor/bin/castor list prod
```

Follow [server-deployment.md](server-deployment.md) for env files, migrations, and reverse-proxy notes. Preserve current deployment behavior unless main changes compose for the upgrade.
