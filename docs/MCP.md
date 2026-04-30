# Librarian MCP — MCP Contract (current)

## 1) Transport and endpoint

Configured in:

- `config/packages/mcp.yaml`
- `config/routes/mcp.yaml`

Current mode:

- **HTTP transport enabled**
- **STDIO transport disabled**
- Endpoint path: **`/_mcp`**

---

## 2) Authentication model

MCP auth is separate from browser session auth.

Firewall:

- `security.firewalls.mcp` (stateless, path `^/_mcp`)
- custom authenticator: `App\Security\McpTokenAuthenticator`

Token source support:

1. `Authorization: Bearer <token>`
2. `X-MCP-Token: <token>`
3. Compatibility fallbacks:
   - raw `Authorization: mcp_...`
   - query param `token` / `access_token` (primarily for inspector/browser stream compatibility)

Authorization rule:

- user must have `ROLE_MCP`

Token lifecycle (`App\Security\McpTokenManager`):

- plaintext token format: `mcp_<random>`
- only SHA-256 hash is stored in DB
- regeneration updates hash + created timestamp
- successful auth updates `mcpTokenLastUsedAt`

---

## 3) Tool surface (stable)

The MCP tool set is fixed and does not change when libraries are added/removed.

Implemented tools:

- `search-libraries`
- `semantic-search`
- `grep`
- `read`

### `search-libraries`

- Inputs: `query`, optional `limit` (1..50)
- Returns only `ready` libraries
- Ranking blends:
  - DB metadata partial match (`name`, `slug`, `description`, `gitUrl`)
  - semantic metadata corpus match (`LibraryMetadataCorpus`)

### `semantic-search`

- Inputs: `library`, `query`, optional `lang`, `path`, `type`, `scope`, `limit`
- Resolves library by slug and requires `status=ready`
- Executes Vera semantic/hybrid search in that single library

### `grep`

- Inputs: `library`, `pattern`, optional `ignoreCase`, `context`, `scope`, `limit`
- Requires `ready` library
- Executes Vera regex grep in that library only

### `read`

- Inputs: `library`, `file`, optional `offset`, `limit`
- Requires `ready` library
- Returns line-windowed output

Read safety checks are strict and cumulative:

1. file must be present in `Library.readableFiles`
2. resolved `realpath` must stay under library root
3. resolved path must be an existing file

---

## 4) Output format

All tool responses are produced by `ToonToolResultFactory`.

- Success: TOON-encoded text payload
- Error: `CallToolResult::error()` with TOON object:
  - `message`
  - `retryable`
  - `hint`

Nuance:
- Empty array success is normalized to literal `[]` so clients can distinguish “empty result” from malformed output.

---

## 5) Readiness and error semantics

`ReadyLibraryResolver` enforces:

- unknown slug => non-retryable “Library not found”
- non-ready library => retryable “Library is not ready”

This keeps tool behavior explicit and avoids partial/inconsistent reads from indexing libraries.

---

## 6) Logging / observability

MCP tools and sync pipeline emit structured logs including:

- tool name
- target library slug
- latency
- failure details + retryability intent

This is intentionally optimized for diagnosing production MCP calls without requiring verbose client traces.
