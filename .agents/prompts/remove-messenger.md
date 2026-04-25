# Remove Messenger from this Symfony template

Welcome AI agent!

Your goal is to remove Symfony Messenger completely from this template while keeping the app bootable and developer workflows clean.

## Constraints

- This project uses Docker-first workflows.
- Do not run Composer or PHP on the host.
- Use container commands (for example through `docker compose` / `castor`).
- Never modify skill/instruction files during cleanup. Treat these paths as read-only:
  - `.opencode/skills/`
  - `.agents/`

## 1) Remove Messenger dependencies

In `composer.json`, remove Messenger-related packages, including:

- `symfony/messenger`
- `symfony/doctrine-messenger` (if present)
- transport-specific packages used only for Messenger in this template (for example AMQP/Redis transport bridge packages)

Then update the lock file from inside Docker.

## 2) Remove Messenger configuration

Delete Messenger config files if present:

- `config/packages/messenger.yaml`
- messenger config overrides in `config/packages/{dev,test,prod}/`

Also remove Messenger-related sections from `config/packages/framework.yaml` when present.

## 3) Remove Messenger environment variables

Clean Messenger env vars from committed env files:

- `.env`
- `.env.dev`
- `.env.prod`
- `.env.prod.local.dist`

Common keys:

- `MESSENGER_TRANSPORT_DSN`
- transport-specific DSN keys used only for Messenger

## 4) Remove worker/runtime wiring

Remove Messenger worker/service wiring from runtime setup:

- `compose.override.yaml`
- `compose.prod.yaml`
- `docker/frankenphp/worker.Caddyfile`
- process/supervisor scripts or entrypoints that run `messenger:consume`

Keep compose and runtime config valid after removal.

## 5) Remove app-level Messenger usage

Delete or refactor app code that depends on Messenger, including:

- message classes in `src/Message/`
- handlers in `src/MessageHandler/`
- dispatch calls using `MessageBusInterface`
- retry/failure handling tied only to async message processing
- tests focused only on Messenger dispatch/consume behavior

If async behavior is no longer needed, replace with direct synchronous service calls where appropriate.

## 6) Clean Castor tasks and docs

Update:

- `castor.php` and `.castor/*.php` (remove messenger consume/worker tasks)
- `README.md`
- `docs/setup.md`
- `docs/castor.md`
- any deployment docs that mention worker processes for Messenger

Ensure docs still describe a coherent template setup.

## 7) Verification

Run Docker-based checks and ensure success:

- `docker compose config`
- `docker compose -f compose.yaml -f compose.prod.yaml config`
- app container boot
- tests/smoke checks and static checks if available

## 8) Final quality gate

Before finishing, confirm no Messenger references remain in tracked source/config/docs (excluding vendor and lock metadata):

- no `symfony/messenger` in `composer.json`
- no Messenger config file in `config/packages/`
- no `MESSENGER_*` env vars in committed env files
- no `messenger:consume` worker/service wiring in compose/runtime files
- no message/handler scaffolding or docs that instruct Messenger setup
