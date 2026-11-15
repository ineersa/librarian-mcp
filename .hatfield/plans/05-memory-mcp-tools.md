# 05 — Native project registration, `memory_search`, and `recall` over HTTP MCP

Status: **implemented**. Dependencies: 03 registration, 04 hybrid indexing, HybridQuery `where`/`params` (upstream PR https://github.com/symfony/ai/pull/2950; local pin in [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md)). Plan 07 Hatfield client work remains separate.

## Surface

Three public MCP tools. Search retains OM's parameter schema; owner-approved recall simplification removes session selection:

| Tool | Required | Optional |
|---|---|---|
| `ensure_project` | None; call with `{}` | None; `additionalProperties: false` |
| `memory_search` | `query` (string, `minLength` 1; no schema `maxLength`; operational UTF-8 byte cap **16384**) | `after`, `before` (`YYYY-MM-DD` or `YYYY-MM-DD HH:MM`), `limit` (1–50, default **20**) |
| `recall` | `id` (`^[a-f0-9]{12,64}$`) | None |

No project-path or session tool argument. `ensure_project` explicitly ensures registration using the connection header; existing admin registration/status remain available. Retired library tools stay retired.

Stack: Symfony MCP bundle **0.15** + `mcp/sdk` **0.9**; attributes `Mcp\Capability\Attribute`; results `Mcp\Schema\Result\CallToolResult` (JSON text + `structuredContent`, `isError` for domain failures). No TOON / hand-written JSON-RPC.

## Connection headers

| Header | Purpose |
|---|---|
| `X-Librarian-Project` | Absolute project root, canonicalized by the server (every call) |

Resolved per HTTP request; never ambient MCP-session project selection. No session header or tool argument: recall derives the evidence session from the matched record. Authentication is server-configured: `MCP_AUTH_REQUIRED=1` (default) requires Bearer or `X-MCP-Token` with `ROLE_MCP`; `0` permits anonymous retrieval on trusted networks. Supplied tokens are always validated; URL/raw Authorization fallbacks are removed. Endpoint `/_mcp`; protocols **2026-07-28** primary (journey-proven) and **2025-11-25** fallback configured.

## Behavior summary

- `ensure_project`: existing registration returns `created: false` without renaming/resetting/enqueueing. A new readable directory uses its basename as display name, existing entity validation and catalog uniqueness rules, then normal asynchronous source discovery. No `.hatfield` or initialized OM prerequisite, source writes, synchronous indexing, or polling. Concurrent inserts converge via existing unique indexes; name/origin conflicts return `project_registration_conflict`, never a different project. Temporary database/queue/Git-inspection failures return retryable `project_registration_unavailable`; invalid/inaccessible roots reuse `invalid_project_context`. Success includes project provenance, source state/error, and index state/searchability; `ok` means registration, not readiness. Same server authentication setting as retrieval, including anonymous mode.
- Search scoped to the selected project’s active generation table; HybridQuery date `where`/`params` before order/limit; bounded candidates + BGE; canonical parent hydration; `partial` / truncation / index freshness as in [docs/MCP.md](../../docs/MCP.md).
- Recall: resolve full ID or prefix across both memory tables in the selected project, derive its stored session, then hydrate evidence. Zero matches: `not_found`. Multiple matches: `ambiguous_id` tool error with full IDs and actual content (up to 50 candidates, with explicit truncation); never prefer one kind. Output retains session/project provenance. Cooperative 30s clock deadline and evidence caps remain documented in MCP.md.
- Domain errors: `{ok:false,error,message,retryable}` with `isError:true`. Full `MemoryErrorCode` table in MCP.md (including `invalid_after` / `invalid_before` / `invalid_date_range` and recall codes).

## Validation (recorded)

Main implementation worktree:

- `vendor/bin/castor cs:fix` passed
- Complete existing headless suite: **5 journeys / 87 assertions** in **13.406s** browser time (27.438s including isolated-stack preparation)
- `vendor/bin/castor qa:static` passed

The existing journey covers UI login/token regeneration, two synthetic projects with identical canonical IDs, automatic indexing, official SDK discovery/schemas, day+minute date bounds, observation prefix recall, reflection full-ID recall, project isolation, and cleanup with source-hash checks. It is updated to send no session header/argument and check derived evidence sessions. Fixtures share source session `108`; do not claim a distinct-session or ambiguity regression matrix. SDK requests keep the printed browser URL and resolve via `LIBRARIAN_PROXY_IP` (Symfony HttpClient `resolve`).

The numbers above record initial plan 05 validation, not the follow-up's latest run. The modern SDK protocol is exercised; the configured handshake fallback remains unverified at runtime. Production is already deployed on port 8093 with auth disabled by owner setup. Plan 06 retains remaining complete-workflow proof; plan 07 Hatfield changes remain separate. No owner-data reset is authorized.

ID-only recall follow-up: `vendor/bin/castor cs:fix`, the existing MCP journey (**1 journey / 47 assertions**, no session header or argument), `qa:static` (including PHPStan and Symfony LSP), and `git diff --check` passed. No collision/negative matrix was added; ambiguity handling is implemented but not separately browser-proven.

`ensure_project` follow-up: the same headless MCP journey passed with **61 assertions** in both required-auth and anonymous modes. It verifies the three-tool/empty-argument schema, new registration through the SDK, repeat registration, existing custom-name preservation, normal automatic discovery/indexing, retrieval, and source hashes. `cs:fix` and `qa:static` passed. No separate concurrency/conflict/fault or uninitialized-source scenario was run. This follow-up is not deployed; existing production authentication is unchanged. Plan 07 calls it once during lazy connection setup without a readiness loop or blocking local observation/reflection.

## Framework references

- Symfony search / MCP Bundle / cookbook / store docs (verified against installed 0.15 / 0.9)
- Upstream HybridQuery filter PR: https://github.com/symfony/ai/pull/2950
- Contract detail: [docs/MCP.md](../../docs/MCP.md)
