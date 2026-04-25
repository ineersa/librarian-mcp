# Remove Mercure from this Symfony template

Welcome AI agent!

Your goal is to remove Mercure completely from this template while keeping the app bootable and developer workflows clean.

## Constraints

- This project uses Docker-first workflows.
- Do not run Composer or PHP on the host.
- Use container commands (for example through `docker compose` / `castor`).
- Never modify skill/instruction files during cleanup. Treat these paths as read-only:
  - `.opencode/skills/`
  - `.agents/`

## 1) Remove Mercure dependencies

In `composer.json`, remove Mercure-related packages:

- `symfony/mercure-bundle`

Then update the lock file from inside Docker.

## 2) Remove Mercure bundle registration

Update `config/bundles.php` and remove:

- `Symfony\\Bundle\\MercureBundle\\MercureBundle`

## 3) Remove Mercure configuration files

Delete Mercure config files if present:

- `config/packages/mercure.yaml`

Also remove Mercure-related keys from other config files if they reference the bundle.

## 4) Remove Mercure environment variables

Clean Mercure env vars from:

- `.env`
- `.env.dev`
- `.env.prod`
- `.env.prod.local.dist`

Common keys to remove:

- `MERCURE_URL`
- `MERCURE_PUBLIC_URL`
- `MERCURE_JWT_SECRET`
- `MERCURE_PUBLISHER_JWT_KEY`
- `MERCURE_SUBSCRIBER_JWT_KEY`
- `MERCURE_PUBLISHER_JWT_ALG`
- `MERCURE_SUBSCRIBER_JWT_ALG`
- `MERCURE_EXTRA_DIRECTIVES`

## 5) Remove Mercure from Docker/Compose

Remove Mercure-specific runtime wiring from:

- `compose.yaml`
- `compose.override.yaml`
- `compose.prod.yaml`
- `docker/frankenphp/Caddyfile`
- `docker/frankenphp/worker.Caddyfile`

Keep Caddy/FrankenPHP config valid after removal.

## 6) Remove app-level Mercure usage

Remove app code and templates that rely on Mercure, including:

- `mercure()` Twig helper usage
- Mercure JS/EventSource subscriptions
- update publishing services using Mercure hub interfaces

If features depend on realtime updates, replace with a no-op or fallback implementation suitable for a base template.

## 7) Clean Castor tasks and docs

Update developer commands and documentation:

- remove Mercure-related tasks in `castor.php` / `.castor/*.php` (if any)
- remove Mercure-specific notes from `README.md`
- remove Mercure setup/deploy references from `docs/setup.md` and `docs/server-deployment.md`
- remove Mercure references from `docs/castor.md`
- remove or delete `docs/mercure.md`

Ensure docs still describe a coherent template setup.

## 8) Verification

Run Docker-based checks and ensure success:

- `docker compose config`
- `docker compose -f compose.yaml -f compose.prod.yaml config`
- container boot checks
- tests/smoke checks (if available)

## 9) Final quality gate

Before finishing, confirm no Mercure references remain in tracked source/config/docs (excluding vendor and lock metadata):

- no `symfony/mercure-bundle` in `composer.json`
- no Mercure bundle in `config/bundles.php`
- no `config/packages/mercure.yaml`
- no `MERCURE_*` env vars in committed env files
- no Mercure config in compose/Caddy files
- no Mercure docs or setup instructions left behind
