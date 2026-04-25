# Database tools via Mate (`ineersa/database-extension`)

Use these instead of ad hoc `information_schema` queries or guessing table/column names. **Inspect schema before `database-query`.**

Execution: **`castor mate-database:database-schema`**, **`castor mate-database:database-query '…'`**, or `mate/mate-tool-call.sh database-schema|database-query '<json>'`.

## Discovery flow (replaces MCP `db://` resources in Cursor)

1. **`database-schema`** — list objects and drill into columns or full metadata.
2. **`database-query`** — read-only `SELECT` / `WITH` only; one statement per call (no semicolons).

## `database-schema` parameters (Castor)

| Option | Purpose |
|--------|---------|
| `--connection` | Doctrine connection name when not default |
| `--filter` | Object name filter (table, view, routine name) |
| `--detail` | `summary` (names), `columns` (types/nullability), `full` (indexes, FKs, etc.) |
| `--match-mode` | `contains`, `prefix`, `exact`, `glob` |
| `--include-views` | Include view definitions in `full` |
| `--include-routines` | Include procedures/functions/triggers in `full` |

## `database-query`

- Argument: SQL string (quote for the shell).
- `--connection` when needed.
- Writes, DDL, and multi-statement scripts are blocked.

## Examples (Castor)

```bash
# Tables (summary)
castor mate-database:database-schema --detail=summary

# Columns for one table
castor mate-database:database-schema --filter=users --detail=columns

# Prefix match
castor mate-database:database-schema --filter=app_ --match-mode=prefix --detail=summary

# Sample rows
castor mate-database:database-query 'SELECT * FROM users WHERE id = 42 LIMIT 1'
```

## Examples (wrapper JSON)

```bash
mate/mate-tool-call.sh database-schema '{"detail":"columns","filter":"users"}'
mate/mate-tool-call.sh database-query '{"query":"SELECT COUNT(*) FROM orders"}'
```

## Errors

Tool output includes `error` and `hint`. Wrong table/column names → run **`database-schema`** again with a broader `--filter` or `--detail=summary`.
