# Castor command guide

This project uses [Castor](https://castor.jolicode.com/) as the only task runner. Prefer the Composer-locked host entry point:

```bash
vendor/bin/castor --version
vendor/bin/castor list
```

If `vendor/bin/castor` is missing, bootstrap locked dependencies once through the development image, then run `vendor/bin/castor tooling:prepare` (see [TESTING.md](../TESTING.md)).

## Task layout

Task definitions live in `.castor/`, imported by `castor.php`. Discover live commands with `vendor/bin/castor list` rather than copying names from memory.

| Area | Examples | Use for |
|---|---|---|
| Tooling | `tooling:prepare`, `tooling:build` | Build QA/runtime image, install deps, prepare Chromium |
| Application | `app:composer`, `app:console` | Composer and Symfony console via Castor runtime; pass native args after `--` |
| Quality | `cs:fix`, `cs:check`, `phpstan`, `lsp:check`, `qa:static`, `check` | Style, static analysis, combined gate |
| Tests | `test`, `browser:stack:*` | Playwright suite and isolated browser stack |
| Mate | `mate:discover`, `mate:tools`, `mate:inspect`, `mate:call`, `mate:collector` | Bounded diagnostics |
| Production-like | `prod:*` (as implemented) | Production compose lifecycle documented below |

## Common commands

### Bootstrap and QA

```bash
vendor/bin/castor tooling:prepare
vendor/bin/castor test:database:fresh
vendor/bin/castor cs:fix
vendor/bin/castor qa:static
vendor/bin/castor test
vendor/bin/castor check
```

### Isolated browser stack

```bash
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
vendor/bin/castor browser:stack:stop
```

Use the printed `http://<checkout>.browser.librarian.localhost:<port>` URL for host exploration and saved browser journeys. Caddy's repository-shared proxy binds loopback, initially on **8082**, leaving finance's proxy untouched. Checkout stacks have separate routes; proxy state and network identity derive from Git's common directory.

The port is repository-wide: worktrees without an explicit `LIBRARIAN_PROXY_PORT` adopt the shared container's published port. An explicit environment or `.env.local` value must match it; conflicts fail before modifying shared routes. To change the port, stop this repository's checkout stacks, run `vendor/bin/castor dev:proxy:stop --for-upgrade` to remove the owned proxy container, then start a stack with the new `LIBRARIAN_PROXY_PORT`. Plain `dev:proxy:stop` preserves the container and its old port binding, so it is not sufficient for a port change. `--for-upgrade` explicitly permits a shared preview outage if routes remain registered. Other worktrees must remove or update conflicting overrides; application data is not deleted.

Native-network PHPUnit joins the proxy network and the checkout-private browser network. `LIBRARIAN_PROXY_IP` supplies Chromium's hostname resolution and an exact Docker hosts entry for Playwright's Node HTTP client; both use the public hostname, not `http://php:8080`. No host networking or database port publishing is needed. Dev/browser stacks retain their own PostgreSQL/pgvector volumes (`pgvector/pgvector:pg17-bookworm`, digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`). Lifecycle: `browser:stack:restart --service=php|worker|...` (and compatible `browser:stack:restart-worker`). Stop checkout stacks before `dev:proxy:stop`; `--for-upgrade` explicitly permits a shared preview outage without deleting application data.

`test` stops the owned worker and Doctrine-drops/creates/migrates only the guarded browser database `librarian_browser` in the checkout-scoped Postgres volume, resetting integer sequences. Alice's `RefreshDatabaseTrait` loads the canonical admin fixture in the PHPUnit browser base. `browser:live:test` reuses the current database and does not provide that reset. To watch the single Projects journey rather than the whole suite:

```bash
vendor/bin/castor test --headed -- tests/E2E/ProjectCrudTest.php
```

### Application console targets

```bash
vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor app:console --browser-database -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor app:console --isolated-database=<id> -- doctrine:database:create --if-not-exists
vendor/bin/castor app:console --isolated-database=<id> -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor browser:stack:console -- app:index-project <project-id> --queue-only
vendor/bin/castor browser:stack:console -- app:index-project <project-id> --drain
```

Default `app:console` executes inside the running development `php` service — start `dev:stack:up` first for migrate/create-admin. Castor target flags belong **before** `--`; Symfony console arguments belong after it. `--browser-database` targets `librarian_browser`. `--isolated-database=<id>` targets `librarian_iso_<id>` on the browser Postgres service and starts only that owned service (no app/worker/reset); create a fresh database before migrating it. `--drain` processes the same bounded indexing units synchronously and clears Doctrine's identity map between units; ordinary operation uses Messenger plus `scheduler_default` with `CallbackMessageProvider` yielding indexing jobs. It does not make source writes or turn search into ingestion. See [indexing design](indexing-design.md) and [indexing validation](indexing-validation.md).

### Mate diagnostics

```bash
vendor/bin/castor mate:tools
vendor/bin/castor mate:inspect monolog-tail
vendor/bin/castor mate:call monolog-tail --json='{"limit":5}'
```

See [mate/AGENT_INSTRUCTIONS.md](../mate/AGENT_INSTRUCTIONS.md).

### Production-like lifecycle

Production compose remains `compose.yaml` + `compose.prod.yaml`. Prefer Castor `prod:*` tasks when present:

```bash
vendor/bin/castor list prod
```

Typical deployment flow is documented in [server-deployment.md](server-deployment.md) and the root [README.md](../README.md). Example shape (confirm with `list`):

```bash
vendor/bin/castor prod:up
vendor/bin/castor prod:console -- doctrine:migrations:migrate --no-interaction
vendor/bin/castor prod:ps
vendor/bin/castor prod:logs
```

## Discover tasks

```bash
vendor/bin/castor list
vendor/bin/castor list --format=md
vendor/bin/castor list --raw
```

## Notes

- Docker commands for the app are executed through Castor-owned compose invocations.
- Dev tasks use `compose.yaml` + `compose.override.yaml`; optional ignored `compose.sources.yaml` supplies deliberately configured read-only source mounts.
- Prod-like tasks use `compose.yaml` + `compose.prod.yaml`, with the same optional source override (use production service name `messenger`, not `worker`).
- Browser tasks always use `compose.browser.yaml` instead of the operator's source override, and prepare only synthetic project sources. `test` restarts the checkout's browser web workers after setup so loaded PHP classes match the code under test.
- User-mapped container commands should use the host UID/GID to keep file ownership correct.
- Do not run host PHP/Composer for application operations once the tooling runtime exists.
- See [framework compatibility](framework-upgrade.md) for dependency constraints and [indexing validation](indexing-validation.md) for recorded checks.
- See [project sources](project-sources.md) for the local-project identity, source discovery, settings, and mount contract.
