# Librarian MCP — MCP Contract (current)

## 1) Transport and endpoint

Configured in:

- `config/packages/mcp.yaml`
- `config/routes/mcp.yaml`

Current mode:

- **HTTP transport enabled**
- **STDIO transport disabled**
- Endpoint path: **`/_mcp`**

MCP bundle **0.15** (with `mcp/sdk` **0.9**) configures the application server as `mcp.servers.librarian` and requires an **explicit tool registry**. The previous TraceableRegistry reset compiler-pass workaround is obsolete and removed with this upgrade.

Allowed HTTP hosts are controlled by `MCP_ALLOWED_HOSTS` (CSV). Default includes `localhost,127.0.0.1,php,server,192.168.2.38`; production must override with external hostnames as needed.

---

## 2) Authentication model

MCP auth is separate from browser session auth.

Firewall:

- `security.firewalls.mcp` (stateless, path `^/_mcp`)
- custom authenticator: `App\Security\McpTokenAuthenticator`

Token policy (plan 03 local-utility decision):

- MCP token headers are **optional**
- When a token **is** supplied, it is validated
- Admin/login remain protected (`ROLE_ADMIN`)

Supported token sources when present:

1. `Authorization: Bearer <token>`
2. `X-MCP-Token: <token>`
3. Compatibility fallbacks:
   - raw `Authorization: mcp_...`
   - query param `token` / `access_token` (primarily for inspector/browser stream compatibility)

Token lifecycle (`App\Security\McpTokenManager`) when tokens are used:

- plaintext token format: `mcp_<random>`
- only SHA-256 hash is stored in DB
- regeneration updates hash + created timestamp
- successful auth updates `mcpTokenLastUsedAt`

MCP callers still cannot register projects or supply arbitrary filesystem paths. Registration and path changes are administrator operations only.

---

## 3) Tool surface (current)

Plan 03 retires the old library tools (`search-libraries`, `semantic-search`, `grep`, `read`) and Vera/TOON library result path.

Until plan 05 lands the memory tools:

- the registry is explicit and empty of public tools
- anonymous `initialize` / `initialized` plus token-bearing `tools/list` cover the optional-auth HTTP smoke path
- `tools/list` returns no tools

Future public tools (plan 05, not implemented here):

- `memory_search`
- `recall`

Do not reintroduce library/Vera tools as a compatibility layer.

---

## 4) Output / readiness notes

There is no library readiness or readable-file sandbox in the current product path. Project source discovery health lives in admin (`pending` / `scanning` / `ready` / `error`) and means **sources discovered**, not indexed.

Indexing, hybrid retrieval, and memory tool payloads are deferred to plans 04–05. See [project-sources.md](project-sources.md) for registration/discovery semantics.

---

## 5) Logging / observability

Keep credentials, observation text, session journals, and plaintext MCP tokens out of routine logs. Prefer structured operational fields (project id, health transition, latency, non-sensitive error class).
