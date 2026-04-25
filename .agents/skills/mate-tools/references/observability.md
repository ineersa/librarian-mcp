# Monolog and Symfony diagnostics via Mate (CLI)

Use Castor **`mate-monolog:monolog-*`** and **`mate-symfony:symfony-*`** (or `mate/mate-tool-call.sh`) for log and profiler inspection inside the Docker `php` service. **MCP-only resources** (`symfony-profiler://…`) are not available in Cursor — use **`symfony-profiler-list`** then **`symfony-profiler-get`**.

## Monolog tools

### `monolog-tail`

Recent log lines.

- Castor: `--lines`, `--level`, `--environment`

### `monolog-search`

Text search; set **`--regex`** (or JSON `"regex":true`) to treat the term as a pattern (replaces a separate “regex-only” tool in older docs).

- Castor: `term` (positional), `--regex`, `--level`, `--channel`, `--environment`, `--from`, `--to`, `--limit`

### `monolog-list-files`

Discover log files and paths.

- Castor: optional `--environment`

### `monolog-list-channels`

Lists channel names seen in logs.

### `monolog-context-search`

Find entries where a context key matches a value.

- Castor: `key` and `value` (positional), plus `--level`, `--environment`, `--limit`

## Symfony tools

### `symfony-services`

Container service IDs → classes; optional filter (like targeted `debug:container`).

- Castor: optional `--query` (partial match on ID or class)

```bash
castor mate-symfony:symfony-services --query=LoggerInterface
mate/mate-tool-call.sh symfony-services '{"query":"event_dispatcher"}'
```

### `symfony-profiler-list`

List profiles; sorted newest first — **`--limit=1`** gives the latest summary.

- Castor: `--limit`, `--method`, `--url`, `--ip`, `--status-code`, `--context`, `--from`, `--to`

### `symfony-profiler-get`

Full profile payload for a token from the list output.

- Castor: `--token=<token>`

## Monolog patterns

```bash
castor mate-monolog:monolog-tail --lines=200 --level=ERROR
castor mate-monolog:monolog-search SQLSTATE --environment=dev --limit=50
castor mate-monolog:monolog-search 'TimeoutException|ConnectException' --regex --limit=100
mate/mate-tool-call.sh monolog-context-search '{"key":"request_id","value":"abc-123"}'
```

## Profiler patterns (no MCP resources)

```bash
castor mate-symfony:symfony-profiler-list --limit=1
castor mate-symfony:symfony-profiler-list --limit=10 --method=GET --status-code=500
castor mate-symfony:symfony-profiler-get --token=<token-from-list>
```

## Parameter guidance

- **`environment`**: when logs are split by Symfony env (`dev`, `prod`, `test`).
- **`limit`**: keep small for interactive use; raise for incident sweeps.
- **`from` / `to`**: narrow noisy time windows.

Sensitive cookies, session payloads, auth headers, and env secrets are redacted in profiler output where the extension applies redaction.
