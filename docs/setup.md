# Development setup

This template runs on FrankenPHP with Symfony worker mode, built-in Mercure, and SQLite.

## Stack overview

- PHP runtime: FrankenPHP (`dunglas/frankenphp`) with PHP 8.5
- App mode: Symfony worker mode via `docker/frankenphp/worker.Caddyfile`
- Realtime: Mercure hub built into FrankenPHP/Caddy
- Database: SQLite at `data/app`
- Compose files:
  - Base: `compose.yaml`
  - Dev overrides: `compose.override.yaml`
  - Production-like overrides: `compose.prod.yaml`

## First-time local setup

1. Build images, start containers, and install dependencies:

   ```bash
   castor dev:setup
   ```

2. Run frontend build bootstrap:

   ```bash
   castor dev:bootstrap
   ```

3. If your project uses Doctrine migrations, run:

   ```bash
   castor dev:console "doctrine:migrations:migrate --no-interaction"
   ```

4. Run Messenger in another terminal:

   ```bash
   castor dev:messenger-consume
   ```

5. Optional while editing CSS/templates:

   ```bash
   castor dev:console "tailwind:build --watch"
   ```

6. Open the app:

   - HTTP: `http://localhost:${HTTP_PORT:-8080}`
   - HTTPS: `https://localhost:${HTTPS_PORT:-8443}`
   - Mailpit UI: `http://localhost:${MAILER_UI_PORT:-8025}`

Port overrides can be set in `.env.local`:

```bash
HTTP_PORT=8081
HTTPS_PORT=8444
MAILER_SMTP_PORT=1026
MAILER_UI_PORT=8026
```

If `castor dev:bootstrap` fails resolving `github.com` during Tailwind binary download, set Docker DNS overrides in `.env.local`:

```bash
DOCKER_DNS_PRIMARY=1.1.1.1
DOCKER_DNS_SECONDARY=8.8.8.8
```

## Common local workflow

- Start: `castor dev:up`
- Stop: `castor dev:down`
- Restart: `castor dev:restart`
- Status: `castor dev:ps`
- Logs: `castor dev:logs`

`castor dev:up`, `castor dev:setup`, and `castor dev:bootstrap` automatically stop running containers from other compose projects that already use one of these configured dev ports.

## Production-like local run

```bash
castor prod:up
```

Useful commands:

- `castor prod:down`
- `castor prod:restart`
- `castor prod:ps`
- `castor prod:logs`
- `castor prod:console "doctrine:migrations:migrate --no-interaction"`
