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

Use the printed loopback URL for host exploration. Saved tests receive `LIBRARIAN_BROWSER_URL=http://php:8080` inside the private browser network. Do not invent ports or Compose project names.

`test` recreates only the verified checkout-owned browser SQLite database before applying migrations. Alice's `RefreshDatabaseTrait` loads the canonical admin fixture in the PHPUnit browser base; SQLite IDs therefore restart on each saved run. `browser:live:test` reuses current storage and does not provide that reset. To watch the single Projects journey rather than the whole suite:

```bash
vendor/bin/castor test --headed -- tests/E2E/ProjectCrudTest.php
```

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
- See [framework upgrade notes](framework-upgrade.md) for dependency resolution and validation evidence.
- See [project sources](project-sources.md) for the local-project identity, source discovery, settings, and mount contract.
