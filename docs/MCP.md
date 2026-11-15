# Librarian MCP — MCP Contract

## 1) Transport and endpoint

Configured in:

- `config/packages/mcp.yaml`
- `config/routes/mcp.yaml`

Current mode:

- **HTTP transport enabled**
- **STDIO transport disabled**
- Endpoint path: **`/_mcp`**

MCP bundle **0.15** (with `mcp/sdk` **0.9**) configures `mcp.servers.librarian` with an explicit tool registry. Attribute namespace on the locked SDK is `Mcp\Capability\Attribute` (`McpTool`, `Schema`).

Protocol versions: **2026-07-28** primary (exercised by the MCP journey) and **2025-11-25** fallback (configured, not separately proven). Allowed HTTP hosts: `MCP_ALLOWED_HOSTS` (CSV).

Upstream HybridQuery date-filter provenance: https://github.com/symfony/ai/pull/2950 — see also [postgres-hybrid-filters.md](postgres-hybrid-filters.md).

---

## 2) Authentication

MCP auth is separate from browser session auth (no cookies on the MCP channel).

Firewall:

- `security.firewalls.mcp` (stateless, path `^/_mcp`)
- `App\Security\McpTokenAuthenticator`

Token is **required** for the endpoint:

1. `Authorization: Bearer <token>`
2. `X-MCP-Token: <token>`

URL query-token fallbacks (`token` / `access_token`) and raw `Authorization: mcp_...` are removed. Failed auth is HTTP **401**. Successful auth requires `ROLE_MCP`. Access is the single-admin registered catalog, not a per-user ACL. Registration remains an administrator operation.

Token lifecycle (`App\Security\McpTokenManager`): plaintext `mcp_<random>`; SHA-256 hash stored; regeneration updates hash + created timestamp; successful auth updates `mcpTokenLastUsedAt`.

---

## 3) Connection headers (not tool arguments)

Configure once on official client `HttpTransport` additional headers; resolve on every HTTP request:

| Header | Required when | Meaning |
|---|---|---|
| `X-Librarian-Project` | every tool call | Absolute registered **canonical** project root |
| `X-Hatfield-Session` | `recall` when `session_id` is omitted | Hatfield OM `run_id` (journal session). **Not** the MCP protocol session id |

No ambient shared MCP-session project selection. Missing/relative/unregistered project → domain error `invalid_project_context` / `project_not_found`. No auto-registration, global search, or arbitrary file tool.

---

## 4) Tools

`tools/list` exposes exactly `memory_search` and `recall`.

### `memory_search`

| Argument | Constraint |
|---|---|
| `query` | required string, `minLength` 1; **no** schema `maxLength`; operational UTF-8 byte cap **16384** |
| `after` | optional `YYYY-MM-DD` or `YYYY-MM-DD HH:MM` |
| `before` | optional same format |
| `limit` | optional integer, min 1, max **50**, default **20** |

Uses the project’s active generation table from candidate retrieval onward. Date bounds filter inside PostgreSQL via HybridQuery `where`/`params` before order/limit (UTC inclusive day/minute via exclusive next-day/minute upper bound). Hybrid stays bounded candidates + BGE (batches of 8). Parents are authoritative canonical full content and original timestamps. Empty results are valid success. Do not claim exhaustive absence from bounded retrieval.

Success top-level: `ok`, `query`, `limit`, `count`, `results`, `truncated`, `partial`, `candidate_truncated`, `result_truncated`, `project`, `index`.

`index` freshness: `{generation_id, state, committed_revision, source_revision}`. `partial` is true when the source revision is ahead, the generation is updating, or stale canonical parents were omitted. Candidate vs result truncation are reported separately.

Each result: `kind`, `id`, `display_id`, `session_id`, `timestamp`, `content`, and observation `importance` (source metadata, not rank score).

### `recall`

| Argument | Constraint |
|---|---|
| `id` | required, pattern `^[a-f0-9]{12,64}$` |
| `session_id` | optional nonblank; default from `X-Hatfield-Session` |

Unique short-id / prefix resolution within the selected session. Observation payload includes `source_refs` / `events` (plus `timestamp`, `importance`). Reflection payload includes `supporting_observation_ids` / `source_refs` / `events`. Both include selected-project provenance. Derived chunk/store IDs are not recall identities.

Native `CallToolResult`: JSON text content + the same object as `structuredContent`; domain failures set `isError: true`.

---

## 5) Errors and limits

Domain failure payload: `{ok: false, error, message, retryable}` with `isError: true`. Native schema/type failures stay protocol-level; authentication failures are HTTP 401.

| Area | Codes |
|---|---|
| Shared / search | `invalid_project_context`, `project_not_found`, `invalid_query`, `invalid_limit`, `invalid_after`, `invalid_before`, `invalid_date_range`, `invalid_session_context`, `index_unavailable`, `embedding_failed`, `retrieval_failed`, `source_unavailable`, `timeout`, `response_too_large`, `internal_error` |
| Recall | `invalid_id`, `invalid_session_id`, `ambiguous_id`, `not_found`, `sessions_unavailable`, `events_unreadable`, `resource_limit_exceeded` |

Limits (honest):

- Response payload max **1 MiB** (JSON text + identical `structuredContent` means the wire duplicates the object).
- Recall default cooperative clock deadline **30s** between DB/journal checkpoints — not a hard I/O timeout on every read.
- Source evidence caps: **10 000** refs / support IDs; **1 MiB** physical journal line / evidence; **64 MiB** scanned journal bytes.
- Search provider HTTP `max_duration` **30s** per model request. BGE batches of 8 mean there is **no** aggregate hard deadline or cancellation claim beyond that.

MCP channel logs stay informational and exclude SDK debug argument logging. Prefer project id, latency, and non-sensitive error class — not query text, source content, tokens, or credentials.

Admin Search memory remains a browser UI over the same derived indexes (no date-window UI). Source discovery health means sources discovered, not indexed.

---

## 6) Official SDK client example (sanitized)

```php
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$endpoint = rtrim(getenv('LIBRARIAN_BROWSER_URL'), '/').'/_mcp'; // supplied printed URL
$proxyIp = getenv('LIBRARIAN_PROXY_IP'); // supplied by Castor for hostname resolve
$hostname = parse_url($endpoint, \PHP_URL_HOST);

$transport = new HttpTransport(
    $endpoint,
    headers: [
        'Authorization' => 'Bearer mcp_REDACTED',
        'X-Librarian-Project' => '/project-sources/example-a', // registered synthetic root
        'X-Hatfield-Session' => '108', // OM run_id when recall omits session_id
    ],
    httpClient: new Psr18Client(HttpClient::create([
        'resolve' => [$hostname => $proxyIp], // keep printed URL; do not rewrite endpoint
    ])),
);

$client = Client::builder()->setClientInfo('librarian-example', '1.0')->build();
try {
    $client->connect($transport);

    $tools = $client->listTools();
    // expect memory_search + recall only

    $search = $client->callTool('memory_search', [
        'query' => 'synthetic fixture observation',
        'after' => '2026-01-01',
        'before' => '2026-12-31 23:59',
        'limit' => 10,
    ]);

    $recall = $client->callTool('recall', [
        'id' => 'aaaaaaaaaaaa', // 12–64 hex prefix / full id
        // optional session_id; header 108 used when omitted
    ]);
} finally {
    $client->disconnect();
}
```

---

## 7) Validation recorded for plan 05

On 2026-11-15, `vendor/bin/castor cs:fix`, the complete existing headless browser suite (**5 journeys / 87 assertions**, 13.406s browser time; 27.438s including isolated-stack preparation), and `vendor/bin/castor qa:static` passed. `git diff --check` passed. Coverage remains one journey per feature; no additional test suite or special-case matrix was introduced. The modern SDK path is exercised; the configured handshake fallback and distinct-session recall remain unverified at runtime. No production deployment or owner-data reset was performed.
