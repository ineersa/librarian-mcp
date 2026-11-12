# Castor command guide

This project uses [Castor](https://castor.jolicode.com/) as the only task runner. Prefer the Composer-locked host entry point:

```bash
vendor/bin/castor --version
vendor/bin/castor list
```

If `vendor/bin/castor` is missing, bootstrap locked dependencies once through the development image, then run `vendor/bin/castor tooling:prepare` (see [TESTING.md](../TESTING.md)).

## Task layout (target after framework upgrade)

Exact file layout is owned by the runtime child. Discover live commands with `vendor/bin/castor list` rather than copying names from memory. Expected public surface:

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
vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
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

Use the printed `LIBRARIAN_BROWSER_URL`. Do not invent ports or Compose project names.

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
- Dev tasks use `compose.yaml` + `compose.override.yaml` (+ browser override when present).
- Prod-like tasks use `compose.yaml` + `compose.prod.yaml`.
- User-mapped container commands should use the host UID/GID to keep file ownership correct.
- Do not run host PHP/Composer for application operations once the tooling runtime exists.
- Framework-upgrade dependency resolution and validation evidence are owned by main; this guide does not claim those checks passed.
