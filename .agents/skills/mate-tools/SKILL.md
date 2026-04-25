---
name: mate-tools
description: Symfony AI Mate via Castor mate-database:*, mate-monolog:*, mate-symfony:*, etc., and mate/mate-tool-call.sh (Docker php service). Self-contained tool mapping and workflows; CLI in Cursor (no MCP). Triggers - mate, symfony ai mate, monolog, profiler, database schema in Docker.
license: MIT
metadata:
  author: OpenCode
  version: "1.1"
---

# Mate Tools (Cursor)

Mate exposes the same **tool names** as an MCP client would use (`mcp:tools:call`). **In Cursor there is no Mate MCP server** — run tools via **Castor `mate-<area>:<task>`** (e.g. `mate-database:database-schema`) or **`mate/mate-tool-call.sh`** inside the Docker `php` service (TOON output, structured errors). Prefer container execution over host PHP.

## When to use

- Composer, PHPStan, PHPUnit, database schema/query, Monolog, Symfony services/profiler, or `server-info`.
- User asks for Mate parameters, output modes, or “MCP equivalent” — answer with **`castor mate-<area>:<task> …`** / wrapper JSON.

## Execution order (strict)

1. **`castor mate-<area>:<task> …`** when the task exists (`castor list mate-database`, `castor list mate-monolog`, … — **`castor list mate` is ambiguous**, do not use).
2. If no matching task: **`mate/mate-tool-call.sh <tool-name> '<json-input>'`** from repo root.
3. **Never** invoke `docker compose exec … vendor/bin/mate` directly (wrapper and Castor use `mate_tool_exec` / the same contract).

Regenerate Castor tasks after Mate extension changes: **`castor dev:mate-generate-castor`**.

## Discovering tasks and schemas

```bash
castor list mate-tools                  # MCP list/inspect only
castor list mate-database --format=md   # full descriptions (very long)
castor list mate-database --format=md --short   # lighter index
castor mate-monolog:monolog-tail --help
castor mate-tools:tools:list --format=toon    # or table, json
castor mate-tools:tools:inspect <tool-name> --format=json
```

**Namespaces:** `mate-database`, `mate-monolog`, `mate-server` (`mate-server:info` → Mate tool `server-info`), `mate-symfony`, `mate-tools` (devtools).

## Wrapper (when not using grouped `castor mate-*` tasks)

```bash
mate/mate-tool-call.sh <tool-name> '<json-input>'
```

- Tool names match **`mcp:tools:call`** (e.g. `phpstan-analyse`, `database-schema`).
- JSON must be valid; use `'{}'` when no fields are required.
- Bootstrap logs: `MATE_TOOL_CALL_SHOW_BOOT_LOGS=1 mate/mate-tool-call.sh …`

---

## Mate extensions → CLI

### Database extension (`ineersa/database-extension`)

**Without MCP resources** (`db://…` is unavailable): discover objects with **`database-schema`**, then run read-only **`database-query`**. Always inspect schema before querying.

| Goal | Mate tool | Castor (examples) |
|------|-----------|-------------------|
| List tables (summary) | `database-schema` | `castor mate-database:database-schema --detail=summary` |
| Column types for a table | `database-schema` | `castor mate-database:database-schema --filter=users --detail=columns` |
| Full structure (indexes/FKs) | `database-schema` | `castor mate-database:database-schema --filter=orders --detail=full` |
| Routine/trigger body | `database-schema` | `castor mate-database:database-schema --filter=trg_name --detail=full --include-routines` |
| View definition | `database-schema` | `castor mate-database:database-schema --filter=active_users --detail=full --include-views` |
| Tables by prefix | `database-schema` | `castor mate-database:database-schema --filter=app_ --match-mode=prefix` |
| Data / counts / row inspect | `database-query` | `castor mate-database:database-query 'SELECT id, name FROM users LIMIT 10'` |

Optional: `--connection=…` on both tasks when using a non-default Doctrine connection.

**Errors:** responses include `error` and `hint`; connection errors hint at connection names. On failure, re-check names with `database-schema`.

More patterns: [references/database.md](references/database.md).

### Server info

| Instead of | Mate tool | Castor |
|------------|-----------|--------|
| `php -v`, `php -m`, OS hints | `server-info` | `castor mate-server:info` |

### Monolog bridge (`symfony/ai-monolog-mate-extension`)

| Instead of | Mate tool | Castor |
|------------|-----------|--------|
| `tail` log file | `monolog-tail` | `castor mate-monolog:monolog-tail --lines=50` |
| grep logs for text | `monolog-search` | `castor mate-monolog:monolog-search error` (add `--regex` for patterns) |

See [references/observability.md](references/observability.md) for filters (`level`, `channel`, `environment`, `from`/`to`) and extra tools (`monolog-list-files`, `monolog-context-search`, …).

### Symfony bridge (`symfony/ai-symfony-mate-extension`)

| Instead of | Mate tool | Castor |
|------------|-----------|--------|
| `bin/console debug:container` (filtered) | `symfony-services` | `castor mate-symfony:symfony-services --query=LoggerInterface` |
| List profiler profiles | `symfony-profiler-list` | `castor mate-symfony:symfony-profiler-list --limit=20` |
| Load profile by token | `symfony-profiler-get` | `castor mate-symfony:symfony-profiler-get --token=<token>` |

**Without MCP resources** (`symfony-profiler://…`): use **`symfony-profiler-list`** (e.g. `--limit=1` for latest), then **`symfony-profiler-get --token=…`**. Sensitive data is redacted in tool output.

---

## Operating rules

- Prefer **`castor mate-<area>:<task>`**; fall back to **`mate/mate-tool-call.sh`** with explicit JSON.
- Use concise modes first (`summary` / defaults), then increase detail.
- Keep JSON valid; match option names from `castor mate-monolog:monolog-tail --help` (etc.) when using Castor.

## Tool catalog (this repo’s generated Castor tasks)

| Area | Tools |
|------|--------|
| Runtime | `server-info` |
| Database | `database-schema`, `database-query` |
| Monolog | `monolog-tail`, `monolog-search`, `monolog-list-files`, `monolog-list-channels`, `monolog-context-search` |
| Symfony | `symfony-services`, `symfony-profiler-list`, `symfony-profiler-get` |

## Quick start

```bash
castor mate-server:info
castor mate-database:database-schema --detail=summary
castor mate-monolog:monolog-tail --lines=100
castor mate-symfony:symfony-services --query=cache
castor mate-symfony:symfony-profiler-list --limit=5
```

## References

- [references/database.md](references/database.md) — schema/query workflow
- [references/observability.md](references/observability.md) — Monolog + profiler
