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

Authentication is selected by **server setup**, not by whether a client sends a header:

- `MCP_AUTH_REQUIRED=1` (default): require a valid admin-issued `ROLE_MCP` token. Missing or invalid credentials return HTTP **401**.
- `MCP_AUTH_REQUIRED=0`: allow requests without a token. Anyone able to reach the endpoint and select a registered project can retrieve its memory, so use this only on a trusted network. A supplied token is still validated; invalid credentials return **401**.

Set the option in `.env.local` / `.env.prod.local` and restart the corresponding stack. Client credentials, when required or supplied, use either header:

1. `Authorization: Bearer <token>`
2. `X-MCP-Token: <token>`

URL query-token fallbacks (`token` / `access_token`) and raw `Authorization: mcp_...` are removed. Access is the single-admin registered catalog, not a per-user ACL. Registration and browser admin authentication remain unchanged.

Token lifecycle (`App\Security\McpTokenManager`): plaintext `mcp_<random>`; SHA-256 hash stored; regeneration updates hash + created timestamp; successful auth updates `mcpTokenLastUsedAt`.

---

## 3) Connection headers (not tool arguments)

Configure once on official client `HttpTransport` additional headers; resolve on every HTTP request:

| Header | Required when | Meaning |
|---|---|---|
| `X-Librarian-Project` | every tool call | Absolute project root; the server canonicalizes it |

No session-selection header is needed. Recall derives the source session from the matched record; MCP protocol session IDs are unrelated. No ambient shared MCP-session project selection. Missing/invalid/inaccessible root → `invalid_project_context`. Search/recall require a registered root (`project_not_found` otherwise); only the explicit `ensure_project` operation can register it. No global search or arbitrary file tool.

Linked Git worktrees are refused with non-retryable `linked_worktree_unsupported`, including already registered roots. `ensure_project` checks before its existing-registration shortcut; search/recall check on project resolution. Git's canonical resolved per-checkout and common metadata directories must coincide. A `.git` file alone does not disqualify a separate-git-dir/submodule checkout. Uninspectable existing Git metadata fails closed. No primary-checkout redirection, registration, source scan, or index rebuild is performed for a refused linked worktree.

---

## 4) Tools

`tools/list` exposes exactly `ensure_project`, `memory_search`, and `recall`. All three use the existing server authentication setting unchanged; anonymous mode permits registration too. Only `ensure_project` writes Librarian-owned registration metadata; search/recall remain read-only retrieval.

### `ensure_project`

Call arguments: `{}`. No project-path or session argument. The native input schema is:

```json
{"type":"object","properties":{},"additionalProperties":false}
```

Canonicalize `X-Librarian-Project` using the same path facilities as retrieval. An existing registration is returned with `created: false`, without renaming, resetting, or enqueueing work. Otherwise register the readable directory with its basename as display name, validate the existing catalog name/root/Git-origin uniqueness rules, persist it, and queue normal asynchronous discovery through `ProjectManager`. Concurrent inserts converge through the existing unique indexes; a different root's name/origin conflict is never resolved by selecting that project.

No `.hatfield` directory or initialized OM database is required to register. Scheduled scans discover sources later; this operation never creates or modifies source directories, settings, databases, or journals. Source database connections remain `mode=ro`; existing writable mounts still permit SQLite WAL/SHM sidecars during discovery. No synchronous indexing or readiness polling.

Example success:

```json
{
  "ok": true,
  "created": true,
  "project": {"id": 3, "name": "new-project", "root": "/home/ineersa/projects/new-project"},
  "source": {"state": "pending", "error": null},
  "index": {"state": "none", "searchable": false}
}
```

`ok: true` means registration exists, **not** that discovery/indexing completed. States reuse `ProjectHealth` and `IndexState` (`none` before a generation exists); `searchable` requires a usable active generation with a committed revision. Existing registrations return their current status. Source readiness and index readiness are independent: search reports `index_unavailable` until usable, while ID-only recall needs discovered canonical sources, not an index.

Hatfield should call this once during lazy Librarian connection setup, not before every search and not in a readiness loop. Local observation/reflection continue regardless of registration/backend readiness. This is a plan 07 integration requirement; no Hatfield runtime code is changed here.

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

Resolve the ID across both observations and reflections within the selected project, regardless of session. A unique match supplies its own source session for journal hydration. Observation payload includes `source_refs` / `events` (plus `timestamp`, `importance`). Reflection payload includes `supporting_observation_ids` / `source_refs` / `events`. Both retain `session_id` as output provenance and include selected-project provenance. Derived chunk/store IDs are not recall identities.

Multiple matches return `ambiguous_id` with `isError: true`, `candidates: [{id, content}, ...]` containing full IDs and actual memory content, plus `candidates_truncated`. At most 50 candidates are listed; the normal response-size limit still applies. Select the intended memory and retry with its full ID. Neither table takes precedence. A full-ID collision is also an error, never an arbitrary selection.

Native `CallToolResult`: JSON text content + the same object as `structuredContent`; domain failures set `isError: true`.

---

## 5) Errors and limits

Domain failure payload: `{ok: false, error, message, retryable}` with `isError: true`. Native schema/type failures stay protocol-level; authentication failures are HTTP 401.

| Area | Codes |
|---|---|
| Shared / search | `invalid_project_context`, `linked_worktree_unsupported`, `project_not_found`, `invalid_query`, `invalid_limit`, `invalid_after`, `invalid_before`, `invalid_date_range`, `index_unavailable`, `embedding_failed`, `retrieval_failed`, `source_unavailable`, `timeout`, `response_too_large`, `internal_error` |
| Registration | `project_registration_conflict` (non-retryable name/origin conflict), `project_registration_unavailable` (retryable database/queue/Git-inspection failure); invalid/inaccessible roots reuse `invalid_project_context` |
| Recall | `invalid_id`, `ambiguous_id`, `not_found`, `sessions_unavailable`, `events_unreadable`, `session_deleted` (non-retryable: the source session was deleted), `resource_limit_exceeded` |

Limits (honest):

- Response payload max **1 MiB** (JSON text + identical `structuredContent` means the wire duplicates the object).
- Recall default cooperative clock deadline **30s** between DB/journal checkpoints — not a hard I/O timeout on every read.
- Source evidence caps: **10 000** refs / support IDs; **1 MiB** physical journal line / evidence; **64 MiB** scanned journal bytes, counted from the nearest source checkpoint before the requested seqs (see §8), so evidence deep in a long journal stays recallable.
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
        // Omit Authorization only when the server has MCP_AUTH_REQUIRED=0.
        'X-Librarian-Project' => '/project-sources/example-a', // readable synthetic root
    ],
    httpClient: new Psr18Client(HttpClient::create([
        'resolve' => [$hostname => $proxyIp], // keep printed URL; do not rewrite endpoint
    ])),
);

$client = Client::builder()->setClientInfo('librarian-example', '1.0')->build();
try {
    $client->connect($transport);

    $tools = $client->listTools();
    // expect ensure_project + memory_search + recall

    $registration = $client->callTool('ensure_project', []); // once at lazy connection setup
    // Handle isError normally. Success is registration, not indexing readiness;
    // continue local observation/reflection, without polling or blocking on indexing.

    $search = $client->callTool('memory_search', [
        'query' => 'synthetic fixture observation',
        'after' => '2026-01-01',
        'before' => '2026-12-31 23:59',
        'limit' => 10,
    ]);

    $recall = $client->callTool('recall', [
        'id' => 'aaaaaaaaaaaa', // 12–64 hex prefix / full id
        // The matched memory supplies its session; no session argument/header.
    ]);
} finally {
    $client->disconnect();
}
```

---

## 7) Validation recorded for plan 05

`ensure_project` follow-up: the same existing headless MCP journey passed with **61 assertions** in each mode: required-auth and anonymous/tokenless registration. It covers exact three-tool discovery and empty input schema, create/repeat/existing-custom-name registration, normal automatic discovery/indexing, search/recall, and source-hash preservation. `cs:fix` and `qa:static` passed. No special concurrency/conflict/fault matrix or separate uninitialized-source scenario was run; database race handling is implementation/source-inspection evidence, not a runtime concurrency proof. This follow-up has not been deployed; production authentication settings are unchanged.

ID-only recall follow-up: `vendor/bin/castor cs:fix`, the existing MCP journey (**1 journey / 47 assertions**), `qa:static` (including PHPStan and Symfony LSP), and `git diff --check` passed. The journey sends no session header/argument and exercises observation prefix and reflection full-ID recall with derived journal evidence plus project isolation. No new collision/negative matrix was added; ambiguity handling is implemented but not separately browser-proven. Earlier results below describe the original session-aware contract.

On 2026-11-15, `vendor/bin/castor cs:fix`, the complete existing headless browser suite (**5 journeys / 87 assertions**, 13.406s browser time; 27.438s including isolated-stack preparation), and `vendor/bin/castor qa:static` passed. `git diff --check` passed. Coverage remains one journey per feature; no additional test suite or special-case matrix was introduced. The modern SDK path is exercised; the configured handshake fallback and distinct-session recall remain unverified at runtime. No production deployment or owner-data reset was performed.

Optional-auth follow-up: the same MCP journey passed with `MCP_AUTH_REQUIRED=0` (**1 journey / 47 assertions**, 5.036s), including an anonymous first connection and an authenticated second connection. The complete existing suite then passed with the default required-auth setting (**5 journeys / 87 assertions**, 12.031s); `cs:fix` and `qa:static` passed. The temporary test-local override was removed.

At the owner's subsequent request, the PR build was deployed on the existing production port **8093**, with private server configuration `MCP_AUTH_REQUIRED=0`, using Castor `prod:up` and `prod:restart`. A tokenless modern-protocol HTTP `tools/list` returned exactly `memory_search` and `recall`; the homepage returned HTTP 200 and web/Postgres were healthy, with the worker running. This is endpoint/authentication deployment verification, not a claim of production search/recall evidence coverage. No database reset, migration, or source modification was performed.

## 8) Observational memory API (plan 09, contract v1)

Plain HTTP endpoints for the Hatfield and Claude Code thin clients, not MCP tools (models never write memory). They use the same authentication and `X-Librarian-Project` header as MCP. `source_kind` / `{sourceKind}` is `hatfield` or `claude_code`.

- `hatfield`: `end_seq` is the `events.jsonl` event seq; journals are read from the discovered sessions directory. Reads stop at Hatfield's committed cut: while `events.jsonl.append.pending.json` exists, bytes past its `offset` are not read.
- `claude_code`: `session_id` is the Claude session UUID and `end_seq` the number of complete transcript lines (1-based line numbers are the seqs; an unterminated last line is ignored). The transcript is read from `CLAUDE_PROJECTS_PATH/<project root with each character outside [A-Za-z0-9] replaced by '-', per UTF-16 code unit as Claude Code's JavaScript does: one '-' for 'é', 'я' or '中', two for a character outside the BMP such as an emoji>/<session_id>.jsonl`, so the header root must be the directory Claude Code was started in, and the service path must equal the host path (no `HATFIELD_PATH_MAPPINGS` translation; very long roots that Claude Code truncates with a hash suffix are not found). `session_id` must be a lowercase UUID or the request is rejected. Sidechain (subagent), meta and compaction-summary records are not observed. Recall evidence is the matched transcript lines' `message` reduced to role, text, tool calls and tool-result text (images and other blocks become type markers; a line over 1 MiB is reported as omitted); a removed transcript (deleted, or expired by Claude's `cleanupPeriodDays`) returns `session_deleted`.

Sources are never rescanned from the top. After the Observer's coverage reaches a seq, Librarian records a source checkpoint: the seq, the byte offset just past its line, and a hash of the bytes before that offset. Later observe and recall reads seek to the nearest checkpoint before the seqs they need and read in bounded chunks (the technique of Hatfield's `JsonlRunEventLog::locatedLines()`). If the hash no longer matches the file, the checkpoint is ignored and that read scans from the top. One Observer job reads at most 8 MiB of in-range lines; a longer backlog (a first observe of a long session) continues in follow-up jobs. A job only stops early where no tool call is still waiting for its result, so a call and its result are always observed together; if a group stays open past twice the budget, the job rolls back to the last such point (or, with a single dangling call and no such point, stops where it is). Lines over 16 MiB (Hatfield's own record limit) are skipped by the Observer.

| Method | Path | Behavior |
|---|---|---|
| `POST` | `/api/om/v1/observe` | Content-free signal `{contract_version: 1, source_kind, session_id, parent_session_id, end_seq, terminal_status}`; registers an unknown project like `ensure_project` and queues the Observer. `202 {ok, contract_version, status: "queued"}`. |
| `GET` | `/api/om/v1/sessions/{sourceKind}/{sessionId}/projection` | Rendered active memory for compaction. `200 {ok, contract_version, text}` (`""` when nothing is active). Never registers a project. |
| `DELETE` | `/api/om/v1/sessions/{sourceKind}/{sessionId}` | The client permanently deleted the session: removes its observations, reflections, coverage and generations; the next incremental index pass drops its chunks. `200 {ok, contract_version, deleted}`; idempotent (`deleted: false` for an unknown project or session). Never registers a project. Only that session: child sessions are deleted by their own calls. |

Errors: `422` invalid/unsupported request, `503` retryable `{ok: false, error, message, retryable}`.
