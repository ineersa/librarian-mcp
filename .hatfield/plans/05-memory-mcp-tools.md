# 05 — Native `memory_search` and `recall` over HTTP MCP

Status: **implemented**. Dependencies: 03 registration, 04 hybrid indexing, HybridQuery `where`/`params` (upstream PR https://github.com/symfony/ai/pull/2950; local pin in [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md)). Plan 07 Hatfield client work remains separate.

## Surface

Two public MCP tools with exact OM parameter schemas:

| Tool | Required | Optional |
|---|---|---|
| `memory_search` | `query` (string, `minLength` 1; no schema `maxLength`; operational UTF-8 byte cap **16384**) | `after`, `before` (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`), `limit` (1–50, default **20**) |
| `recall` | `id` (`^[a-f0-9]{12,64}$`) | `session_id` (nonblank) |

No `project_path` tool argument. Registration/status stay admin operations. Retired library tools stay retired.

Stack: Symfony MCP bundle **0.15** + `mcp/sdk` **0.9**; attributes `Mcp\Capability\Attribute`; results `Mcp\Schema\Result\CallToolResult` (JSON text + `structuredContent`, `isError` for domain failures). No TOON / hand-written JSON-RPC.

## Connection headers

| Header | Purpose |
|---|---|
| `X-Librarian-Project` | Absolute registered canonical project root (every call) |
| `X-Hatfield-Session` | OM `run_id`; required for `recall` only when `session_id` is omitted. Not the MCP session id |

Resolved per HTTP request; never ambient MCP-session project selection. Auth: Bearer or `X-MCP-Token` required; URL/raw Authorization fallbacks removed; `ROLE_MCP`; single-admin catalog. Endpoint `/_mcp`; protocols **2026-07-28** primary (journey-proven) and **2025-11-25** fallback configured.

## Behavior summary

- Search scoped to the selected project’s active generation table; HybridQuery date `where`/`params` before order/limit; bounded candidates + BGE; canonical parent hydration; `partial` / truncation / index freshness as in [docs/MCP.md](../../docs/MCP.md).
- Recall: current-session default via header; explicit `session_id` parameter path; observation/reflection evidence + project provenance; cooperative 30s clock deadline and evidence caps documented in MCP.md.
- Domain errors: `{ok:false,error,message,retryable}` with `isError:true`. Full `MemoryErrorCode` table in MCP.md (including `invalid_after` / `invalid_before` / `invalid_date_range` and recall codes).

## Validation (recorded)

Main implementation worktree:

- `vendor/bin/castor cs:fix` passed
- Complete existing headless suite: **5 journeys / 87 assertions** in **13.406s** browser time (27.438s including isolated-stack preparation)
- `vendor/bin/castor qa:static` passed

Journey coverage: UI login + token regenerate; register two synthetic projects with identical canonical IDs and OM run `108`; automatic indexing; official SDK discovery/schemas; search with day+minute bounds; observation prefix recall on current session (header `108`); reflection recall with explicit `session_id=108`; project B search + recall isolation; cleanup with source-hash checks. Explicit `session_id` is proven; calling that **cross-session proof** would overclaim because the header value is the same `108`. SDK client keeps the printed browser URL and resolves the hostname through supplied `LIBRARIAN_PROXY_IP` (Symfony HttpClient `resolve`).

The modern SDK protocol is exercised; the configured handshake fallback remains unverified at runtime. Plans 06 (production) and 07 (Hatfield) remain TODO / out of scope. No production deploy or owner-data reset.

## Framework references

- Symfony search / MCP Bundle / cookbook / store docs (verified against installed 0.15 / 0.9)
- Upstream HybridQuery filter PR: https://github.com/symfony/ai/pull/2950
- Contract detail: [docs/MCP.md](../../docs/MCP.md)
