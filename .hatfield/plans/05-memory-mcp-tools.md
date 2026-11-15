# 05 — Native `memory_search` and `recall` over HTTP MCP

Status: approved contract for implementation (docs revision). Dependencies: 03 registration, 04 hybrid indexing, HybridQuery `where`/`params` pin ([docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md)). Freeze this contract before plan 07’s Hatfield client work.

## Requested surface

Librarian exposes **two** public MCP tools with **exact current OM parameter schemas**:

| Tool | Required | Optional |
|---|---|---|
| `memory_search` | `query` (string, `minLength` 1) | `after`, `before` (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`), `limit` (integer 1–50, default **20**) |
| `recall` | `id` (pattern `^[a-f0-9]{12,64}$`) | `session_id` (nonblank string) |

No `project_path` (or any project selector) on the tool schemas. Do not restore retired library tools. Registration/status stay admin operations.

Use installed Symfony MCP bundle **0.15** + `mcp/sdk` **0.9**. Attribute namespace on the locked SDK is `Mcp\Capability\Attribute` (`McpTool`, `Schema`) — online docs may still show `Mcp\Server\Capability\Attribute`; verify against vendor. Return native `Mcp\Schema\Result\CallToolResult` with JSON text content + `structuredContent`; set `isError` for domain failures. No TOON dependency and no hand-written JSON-RPC/transport.

## Connection-scoped project and session

Configure once on the official client `HttpTransport` additional headers (not model-facing tool args):

| Header | Purpose |
|---|---|
| `X-Librarian-Project` | Absolute registered canonical project root |
| `X-Hatfield-Session` | Hatfield OM `run_id` (session journal id). Required for `recall` when `session_id` is omitted. **Not** the MCP protocol session id. |

Resolve these headers on **every HTTP request**. Never store ambient shared MCP-session project selection. Missing/relative/unregistered project context is a structured failure, not auto-registration, global search, or a fallback project. No arbitrary filesystem-reading tool.

Auth (supersedes earlier optional-auth empty-registry smoke):

- MCP token **required**: `Authorization: Bearer <token>` or `X-MCP-Token`
- Remove URL query-token fallbacks (`token` / `access_token`)
- Successful auth grants `ROLE_MCP`; single-admin catalog, not per-user ACL
- Endpoint remains `/_mcp`; configure protocol versions **2026-07-28** primary and **2025-11-25** fallback explicitly

## memory_search

- Scope retrieval to the header-selected project’s active generation table from candidate retrieval onward (no post-rank cross-project filter).
- Hybrid pipeline stays bounded (~100 candidates) + local BGE; stock weighted pgvector + `ts_rank` with whitespace-separated OR texts; full query for embeddings/rerank.
- Date filters use the Composer-pinned Postgres HybridQuery `where`/`params` support against existing `occurred_at` metadata **before** order/limit. UTC inclusive day/minute semantics via exclusive next-day/minute upper bound. Details: [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md). Do not silently drop date args or pretend post-filtering the candidate list is equivalent.
- No JSON Schema `maxLength` on `query`; an operational byte/size cap may be documented separately.
- Validate committed manifest / content signature; hydrate parents read-only from canonical OM sources.
- Report partial/freshness and candidate/result truncation as distinct fields. Importance is source metadata, not ranking score. Empty results are valid success.

### Search response shape (contract)

Top-level: `ok`, `query`, `limit`, `count`, `results`, `truncated`, `partial`, `candidate_truncated`, `result_truncated`, `project`, `index`.

Each result: `kind`, `id`, `display_id`, `session_id`, `timestamp`, `content`, and `importance` for observations.

## recall

Preserve OM recall behavior inside Librarian (plan 05 owns both tools; plan 07 still uses the official SDK client against this server):

- Default to the connection `X-Hatfield-Session` when `session_id` is omitted; explicit `session_id` is cross-session.
- Unique short-id / prefix resolution within that session scope.
- Full OM evidence: observation or reflection payload, reflection supports, and `events.jsonl` hydration as OM does today.
- Enrich the OM payload with selected-project provenance. Derived chunk/store IDs are not recall identities.

## Errors and limits

Domain failures use `CallToolResult` with `isError` and structured codes. Likely codes (main finalizes the typed table): `invalid_project_context`, `project_not_found`, `index_unavailable`, `embedding_failed`, `retrieval_failed`, `source_unavailable`, `invalid_query`, `invalid_limit`, `invalid_after`, `invalid_before`, `invalid_date_range`, `timeout`, `response_too_large`, plus native recall validation/not-found codes. SDK schema/type failures stay native MCP errors.

Existing provider HTTP calls each use `max_duration` 30s. Do not claim a hard aggregate deadline or cooperative cancellation beyond what the stack actually provides; document limits honestly. Keep query text, source content, tokens, and credentials out of routine logs.

## Acceptance / validation (planned)

Extend the **single** existing MCP happy-path journey only:

1. Login + regenerate MCP token through the admin UI (token now required).
2. Register two tiny synthetic sources that share the same OM session id; wait for automatic indexing.
3. Official PHP SDK over real HTTP: discover exactly `memory_search` and `recall` (OM schemas, no project arg).
4. Search with date bounds; recall current-session default and explicit cross-session; prove header project context isolates results across the two registrations.

No new suite and no special negative/security/concurrency matrices without owner approval. **Do not claim validation passed in this docs revision** — main records Castor/browser results later. Plan 06 live deployment and plan 07 Hatfield client changes are out of scope here.

## Framework references checked for this revision

- Symfony search: https://r.jina.ai/https://symfony.com/search?q=MCP%20bundle%20McpTool%20attribute
- MCP Bundle: https://symfony.com/doc/current/ai/bundles/mcp-bundle.html (online examples use `Mcp\Server\Capability\Attribute`; installed 0.9 uses `Mcp\Capability\Attribute`)
- Cookbook: https://symfony.com/doc/current/ai/cookbook/build-an-mcp-server.html
- Store component: https://symfony.com/doc/current/ai/components/store.html
- Verified installed `CallToolResult`, `HttpTransport` additional headers, and HybridQuery fork pin documented in [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md)
