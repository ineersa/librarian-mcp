---
name: castor
description: Runs and discovers project tasks via host vendor/bin/castor for Docker lifecycle, Symfony console, Composer, Playwright tests, and quality checks. Use when the user mentions castor, task runner, tooling:prepare, app:console, qa:static, browser stack, containerized PHP, migrations in Docker, or replacing make/ad-hoc docker compose with project tasks.
license: MIT
metadata:
  author: Librarian MCP
  version: "2.0"
---

# Castor

This repository uses a Composer-locked Castor binary as the single public tooling entry point: **`vendor/bin/castor`**.

## Rules

- Prefer `vendor/bin/castor` over ad hoc `docker compose` / host PHP when a task exists.
- Castor owns Docker/runtime dispatch; do not prefix Castor with another runner or choose application PHP manually.
- Discover tasks with `vendor/bin/castor list` rather than inventing command names from memory or copying finance-project task names blindly.
- Pass native Composer, console, and PHPUnit arguments after `--` when the task supports passthrough.
- Read `var/qa/<task>/result.json` on failure; do not dump full reports into agent context by default.

## Primary surface

```sh
vendor/bin/castor tooling:prepare
vendor/bin/castor app:composer -- install --no-interaction
vendor/bin/castor app:console -- about
vendor/bin/castor cs:fix
vendor/bin/castor qa:static
vendor/bin/castor test
vendor/bin/castor check
```

Isolated browser stack (exact task names from `vendor/bin/castor list`):

```sh
vendor/bin/castor browser:stack:up
vendor/bin/castor browser:stack:status
```

Mate diagnostics: `mate:discover`, `mate:tools`, `mate:inspect`, `mate:call`, `mate:collector`.

Production-like and remaining lifecycle tasks are documented in [docs/castor.md](../../../docs/castor.md). Discover existing tasks before adding a small operation; do not fabricate wrappers or duplicate the runner.

## When adding or debugging Castor tasks

Task entry points live under `.castor/` and `castor.php`. Vendored Castor docs live under `references/upstream/` when present. For a concise index of built-in functions, attributes, and environment variables, see [references/castor-framework-reference.md](references/castor-framework-reference.md).
