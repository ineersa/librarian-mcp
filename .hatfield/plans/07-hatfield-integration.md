# 07 — Final task: Hatfield OM Librarian search/recall bridge and semantic removal

Status: TODO; future **separate Hatfield** implementation/review, not part of the Librarian rewrite diff.
Dependencies: frozen and tested plan 05 MCP contract (`ensure_project` plus both retrieval tools); working production service from 06. Do not delete the local semantic implementation before the replacement is ready.

## Latest user direction

Move **both** OM retrieval tools onto Librarian's HTTP MCP surface. Search retains OM's query/date/limit schema. Recall accepts **only `id`**, searches across this project's observations/reflections, and derives the evidence session from the matched record. Project selection is connection-level (`X-Librarian-Project`), not a tool argument. There is no session-selection header or tool argument.

- Add an OM configuration option to use Librarian.
- If not configured, retain regular project-local search and recall.
- Remove Hatfield’s semantic indexing/search implementation completely once the external path is validated.
- Keep observation/reflection/coverage/compaction local.
- Use the official PHP MCP SDK client (`HttpTransport` headers configured once) to call Librarian’s `memory_search` and `recall`.
- During lazy Librarian connection setup, call `ensure_project` once with `{}`; not before every search. It uses the unchanged server authentication mode, including anonymous setup. Keep it internal to connection setup rather than adding a new OM/model-facing registration tool.
- Keep external MCP connection settings under OM settings rather than duplicate ordinary Hatfield MCP tool registrations.
- Enforce OM disabled for child/fork runs in Hatfield, removing any child-OM opt-in. Librarian does not add child-journal resolution; existing child-OM records can remain unsupported for evidence recall.

This supersedes earlier search-only/local-only recall, per-call project-root, and session-scoped recall drafts. Update Hatfield's recall schema/guidance and local implementation to ID-only project-wide resolution too, keeping both modes consistent.

## Behavior matrix

| OM configuration | memory_search | recall | Observation/reflection |
|---|---|---|---|
| No Librarian | Existing literal/substring search over local retained memories | ID-only exact/prefix project-wide source recall; derive evidence session | Unchanged/local |
| Librarian configured | SDK call with OM query/after/before/limit; project via `X-Librarian-Project` | SDK call with only `id`; server derives evidence session | Unchanged/local |

“Regular search” means the existing nonsemantic local behavior, not a new local BM25/vector backend. Preserve OM validation/date bounds/output conventions unless an explicit reviewed contract change is needed. No silent fallback from a failed configured Librarian call to local search; report the configured backend failure unless the user explicitly requests another policy.

## Settings and connection contract (finalize before coding)

Propose exact YAML field names for review before implementation. Cover HTTP endpoint, auth/secret reference, bounded timeout, and any operational limits required by the SDK. Headers:

- Optional `Authorization: Bearer …` or equivalent secret wiring for `ROLE_MCP`; required when the server has `MCP_AUTH_REQUIRED=1`, omitted when anonymous retrieval is explicitly configured
- `X-Librarian-Project` = current absolute Hatfield project root from existing path facilities

Do not add a separate configured project ID or model-facing project selector. Never store/log secrets in docs, exceptions, prompts, or committed settings. Distinguish disabled/unconfigured from malformed configured external retrieval. Global/user-installed OM may serve multiple projects through the same endpoint; each client context carries its own headers—do not persist ambient project selection in the shared MCP protocol session.

Librarian canonicalizes the absolute root. Lazy connection setup explicitly calls `ensure_project`: existing registration returns unchanged; a new readable directory is registered using its basename and normal asynchronous discovery. No `.hatfield` directory or initialized OM is required, and Librarian must never create/modify source directories, OM, settings, or journals. Name/origin conflicts are clear tool errors, never selection of a different project. There is no separate “project exists” tool or search-triggered implicit registration. Update Hatfield settings docs/schema together under Hatfield project rules and remove obsolete semantic settings rather than unsupported aliases.

## SDK bridge and lifecycle

- Inspect current PHP SDK + existing Hatfield MCP client facilities first; reuse transports, cancellation, logging, and lifecycle within Deptrac/ExtensionApi rules.
- Use SDK HTTP transport / tool-call / `CallToolResult` facilities. No manual JSON-RPC or shell forwarding.
- Bound connect/request deadlines; preserve MCP `isError` and structured validation semantics. Never turn an external error into a successful empty result.
- Define client init/reuse/close/reset for actual tool-worker ownership. A client is not an excuse for a new MCP server process or per-session indexer.
- After lazy connect, call `ensure_project` once with `{}` and preserve the native failure convention. Its `ok: true` means registration exists, not that discovery/indexing completed; do not wait, poll, or repeat it before searches. Source/index states are informational. Search reports `index_unavailable` until usable; ID-only recall can work after canonical sources are discovered without waiting for an index. Local observation/reflection must continue regardless of connection, registration, or index readiness. No new client/server authentication policy or synchronous indexing.
- Search arguments stay OM-identical; recall takes only `id`. Project context stays in its header; source session context is derived by lookup, never configured or forwarded.

## Instructions and tool contract

- Keep user/model-facing tools named `memory_search` and `recall`.
- Local mode keeps literal-substring guidance. External mode describes hybrid search of this project only and freshness/partial semantics. Both modes recall by canonical full ID or unique prefix; `session_id` remains output provenance, not a required input.
- Resolve against both observations and reflections before choosing. No match returns `not_found`; ambiguity returns a tool error with full IDs and actual memory content, so the model can choose and retry with a full ID. Preserve candidate truncation and response-size limits; never silently prefer a memory kind or session.
- Update generated/injected search guidance and `/om-view`-related references. Preserve provenance guidance: importance is source metadata, not confidence.
- Cross-project/global search remains excluded.

## Remove semantic implementation from Hatfield

Inventory and remove rather than merely disable: semantic settings/schema, embedding/reranker clients used only here, semantic jobs/handlers/adapters, tool/prompt branches advertising local hybrid retrieval, semantic-only tests/Castor tasks/docs/deps after checking other owners.

Preserve canonical OM schema/history and observation/reflection/coverage. Removal does **not** authorize deleting real memory data or rewriting migration history casually. Propose any derived-table cleanup separately under Hatfield rules. Preserve evaluation questions/sanitized reports in Librarian before removing their original home.

Relevant Hatfield references (inspect before coding): `ObservationalMemoryExtension.php`, `OmSettings` / configuration, `SearchToolHandler`, `RecallToolHandler`, `OmQueryService`, `Semantic/`, schema migrator hooks, `.castor/om-semantic.php`.

## Required proof

1. Local mode: literal matching/date bounds/limits, ID-only project-wide exact/prefix recall with derived session and supporting evidence, cancellation/errors; no semantic clients/jobs/index allocations.
2. External mode: real official SDK HTTP against the validated Librarian contract (once-per-lazy-setup `ensure_project` with `{}`, both retrieval tools, project header, no session input, existing server auth mode). Preserve ambiguity candidates for full-ID retry. Confirm registration is not a readiness gate or a per-search/polling call. Validate configured failures/lifecycle under Hatfield test rules; Librarian unavailability must not break local observation/reflection.
3. Both modes: real turns persist observations/coverage; `/om-view` remains useful; external retrieval does not alter observation capacity.
4. No duplicate tools or ordinary-MCP registration requirement; no external connection in local mode.
5. Matched memory measurements: extension worker no longer runs semantic jobs; multiple Hatfield sessions share one Librarian deployment. Record bridge/client overhead separately.
6. Focused Castor checks then the project-required full gate for LLM-visible/runtime changes. Follow Hatfield testing skill, tests/AGENTS, settings isolation, tmux/process safety, Deptrac, and review rules.

## Acceptance / handoff

Provide the separately reviewed Hatfield diff, finalized connection/header contracts, both-tool proof, multi-turn observation and shared-service evidence, memory comparison, updated docs, removed semantic inventory, and remaining migration/artifact limitations. Do not bundle Librarian or unrelated generic MCP changes into this final Hatfield task.
