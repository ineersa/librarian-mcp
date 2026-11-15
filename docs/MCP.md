# Librarian MCP — MCP Contract (current + plan 05)

## 1) Transport and endpoint

Configured in:

- `config/packages/mcp.yaml`
- `config/routes/mcp.yaml`

Current mode:

- **HTTP transport enabled**
- **STDIO transport disabled**
- Endpoint path: **`/_mcp`**

MCP bundle **0.15** (with `mcp/sdk` **0.9**) configures the application server as `mcp.servers.librarian` and requires an **explicit tool registry**.

Protocol versions (plan 05): configure **2026-07-28** as primary and **2025-11-25** as fallback explicitly. Allowed HTTP hosts remain `MCP_ALLOWED_HOSTS` (CSV).

---

## 2) Authentication model

MCP auth is separate from browser session auth.

Firewall:

- `security.firewalls.mcp` (stateless, path `^/_mcp`)
- custom authenticator: `App\Security\McpTokenAuthenticator`

**Plan 05 auth (approved; supersedes earlier optional-auth empty-registry smoke):**

- MCP token is **required** for tool use (private memory data).
- Accepted headers only:
  1. `Authorization: Bearer <token>`
  2. `X-MCP-Token: <token>`
- **Remove** URL query-token fallbacks (`token` / `access_token`) and raw `Authorization: mcp_...` compatibility once plan 05 lands.
- Successful auth requires `ROLE_MCP`. Catalog is single-admin, not per-user ACL.

Token lifecycle (`App\Security\McpTokenManager`):

- plaintext format: `mcp_<random>`
- only SHA-256 hash stored
- regeneration updates hash + created timestamp
- successful auth updates `mcpTokenLastUsedAt`

MCP callers still cannot register projects. Registration remains an administrator operation.

---

## 3) Connection headers (not tool arguments)

Configure once on the official client `HttpTransport` additional headers; resolve on every HTTP request:

| Header | Required when | Meaning |
|---|---|---|
| `X-Librarian-Project` | every tool call | Absolute registered **canonical** project root |
| `X-Hatfield-Session` | `recall` when `session_id` omitted | Hatfield OM `run_id` (journal session). **Not** the MCP protocol session id |

No ambient shared MCP-session project selection. Missing/relative/unregistered project context → structured domain error (`invalid_project_context` / `project_not_found`). No auto-registration, global search, or arbitrary file tool.

---

## 4) Tool surface (plan 05)

`tools/list` exposes exactly:

### `memory_search`

| Argument | Constraint |
|---|---|
| `query` | required string, `minLength` 1 (no schema `maxLength`; operational size cap may apply separately) |
| `after` | optional `YYYY-MM-DD` or `YYYY-MM-DD HH:MM` |
| `before` | optional same format |
| `limit` | optional integer, min 1, max 50, default **20** |

Uses the existing Symfony AI hybrid pipeline on the selected project’s generation table. Date bounds filter inside PostgreSQL via HybridQuery `where`/`params` (Composer-pinned fork until upstream; see [postgres-hybrid-filters.md](postgres-hybrid-filters.md)). Bounded candidates (~100) + BGE; report truncation and index freshness separately.

Success payload (structured + JSON text): top-level `ok`, `query`, `limit`, `count`, `results`, `truncated`, `partial`, `candidate_truncated`, `result_truncated`, `project`, `index`. Each result: `kind`, `id`, `display_id`, `session_id`, `timestamp`, `content`, and observation `importance` (source metadata, not rank score). Empty results are valid success.

### `recall`

| Argument | Constraint |
|---|---|
| `id` | required, pattern `^[a-f0-9]{12,64}$` |
| `session_id` | optional nonblank; default from `X-Hatfield-Session` |

Full OM evidence (observation/reflection, supports, `events.jsonl` hydration), unique prefix resolution, current-session default vs explicit cross-session. Enrich with selected-project provenance. Native `CallToolResult` (`isError` for domain failures). Attribute namespace on installed SDK: `Mcp\Capability\Attribute`.

Until plan 05 is implemented, the registry remains without public tools and older optional-auth smoke wording is historical only.

---

## 5) Errors and limits

Domain errors: `CallToolResult` with `isError` and structured codes (main finalizes the typed table). Likely: `invalid_project_context`, `project_not_found`, `index_unavailable`, `embedding_failed`, `retrieval_failed`, `source_unavailable`, `invalid_query`, `invalid_limit`, `invalid_after`, `invalid_before`, `invalid_date_range`, `timeout`, `response_too_large`, plus native recall validation/not-found codes. SDK schema/type failures remain protocol-level.

Provider HTTP calls each use `max_duration` 30s. Do not claim a hard aggregate deadline or cancellation beyond what the stack provides.

Admin Search memory remains a browser UI over the same derived indexes; it is not an MCP tool and still has no date-window UI. Source discovery health (`pending` / `scanning` / `ready` / `error`) means sources discovered, not indexed.

---

## 6) Logging / observability

Keep credentials, observation text, session journals, and plaintext MCP tokens out of routine logs. Prefer structured operational fields (project id, latency, non-sensitive error class).
