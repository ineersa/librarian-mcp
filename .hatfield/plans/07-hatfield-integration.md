# 07 — Final task: Hatfield OM Librarian search/recall bridge and semantic removal

Status: TODO; future **separate Hatfield** implementation/review, not part of the Librarian rewrite diff.
Dependencies: frozen and tested plan 05 MCP contract (both tools); working production service from 06. Do not delete the local semantic implementation before the replacement is ready.

## Latest user direction

Move **both** OM retrieval tools onto Librarian’s HTTP MCP surface, matching OM’s exact parameter schemas. Project selection is connection-level (`X-Librarian-Project`), not a tool argument. Session default for recall uses `X-Hatfield-Session` (OM `run_id`).

- Add an OM configuration option to use Librarian.
- If not configured, retain regular project-local search and recall.
- Remove Hatfield’s semantic indexing/search implementation completely once the external path is validated.
- Keep observation/reflection/coverage/compaction local.
- Use the official PHP MCP SDK client (`HttpTransport` headers configured once) to call Librarian’s `memory_search` and `recall`.
- Keep external MCP connection settings under OM settings rather than duplicate ordinary Hatfield MCP tool registrations.

This supersedes earlier “search-only / local-only recall” and “absolute project root on every tool call” drafts.

## Behavior matrix

| OM configuration | memory_search | recall | Observation/reflection |
|---|---|---|---|
| No Librarian | Existing literal/substring search over local retained memories | Existing exact/prefix local source recall | Unchanged/local |
| Librarian configured | SDK call with OM query/after/before/limit; project via `X-Librarian-Project` | SDK call with OM id/session_id; default session via `X-Hatfield-Session` | Unchanged/local |

“Regular search” means the existing nonsemantic local behavior, not a new local BM25/vector backend. Preserve OM validation/date bounds/output conventions unless an explicit reviewed contract change is needed. No silent fallback from a failed configured Librarian call to local search; report the configured backend failure unless the user explicitly requests another policy.

## Settings and connection contract (finalize before coding)

Propose exact YAML field names for review before implementation. Cover HTTP endpoint, auth/secret reference, bounded timeout, and any operational limits required by the SDK. Headers:

- `Authorization: Bearer …` or equivalent secret wiring for `ROLE_MCP`
- `X-Librarian-Project` = current absolute Hatfield project root from existing path facilities
- `X-Hatfield-Session` = current OM `run_id`

Do not add a separate configured project ID or model-facing project selector. Never store/log secrets in docs, exceptions, prompts, or committed settings. Distinguish disabled/unconfigured from malformed configured external retrieval. Global/user-installed OM may serve multiple projects through the same endpoint; each client context carries its own headers—do not persist ambient project selection in the shared MCP protocol session.

Librarian matches the registered canonical root. An unregistered/unavailable project is a clear failure, never registration or a different-project fallback. Update Hatfield settings docs/schema together under Hatfield project rules and remove obsolete semantic settings rather than unsupported aliases.

## SDK bridge and lifecycle

- Inspect current PHP SDK + existing Hatfield MCP client facilities first; reuse transports, cancellation, logging, and lifecycle within Deptrac/ExtensionApi rules.
- Use SDK HTTP transport / tool-call / `CallToolResult` facilities. No manual JSON-RPC or shell forwarding.
- Bound connect/request deadlines; preserve MCP `isError` and structured validation semantics. Never turn an external error into a successful empty result.
- Define client init/reuse/close/reset for actual tool-worker ownership. A client is not an excuse for a new MCP server process or per-session indexer.
- Tool arguments stay OM-identical; project/session context stays in headers.

## Instructions and tool contract

- Keep user/model-facing tools named `memory_search` and `recall`.
- Local mode keeps literal-substring guidance. External mode describes hybrid search of this project only, freshness/partial semantics, and using returned canonical IDs/session IDs with Librarian `recall` (or local recall only in local mode—finalize wording against the frozen plan 05 payloads).
- Update generated/injected search guidance and `/om-view`-related references. Preserve provenance guidance: importance is source metadata, not confidence.
- Cross-project/global search remains excluded.

## Remove semantic implementation from Hatfield

Inventory and remove rather than merely disable: semantic settings/schema, embedding/reranker clients used only here, semantic jobs/handlers/adapters, tool/prompt branches advertising local hybrid retrieval, semantic-only tests/Castor tasks/docs/deps after checking other owners.

Preserve canonical OM schema/history and observation/reflection/coverage. Removal does **not** authorize deleting real memory data or rewriting migration history casually. Propose any derived-table cleanup separately under Hatfield rules. Preserve evaluation questions/sanitized reports in Librarian before removing their original home.

Relevant Hatfield references (inspect before coding): `ObservationalMemoryExtension.php`, `OmSettings` / configuration, `SearchToolHandler`, `RecallToolHandler`, `OmQueryService`, `Semantic/`, schema migrator hooks, `.castor/om-semantic.php`.

## Required proof

1. Local mode: literal matching/date bounds/limits, exact/prefix recall, supporting evidence, cancellation/errors; no semantic clients/jobs/index allocations.
2. External mode: real official SDK HTTP against the validated Librarian contract (both tools, headers). Validate configured failures/lifecycle under Hatfield test rules; Librarian unavailability must not break local observation/reflection.
3. Both modes: real turns persist observations/coverage; `/om-view` remains useful; external retrieval does not alter observation capacity.
4. No duplicate tools or ordinary-MCP registration requirement; no external connection in local mode.
5. Matched memory measurements: extension worker no longer runs semantic jobs; multiple Hatfield sessions share one Librarian deployment. Record bridge/client overhead separately.
6. Focused Castor checks then the project-required full gate for LLM-visible/runtime changes. Follow Hatfield testing skill, tests/AGENTS, settings isolation, tmux/process safety, Deptrac, and review rules.

## Acceptance / handoff

Provide the separately reviewed Hatfield diff, finalized connection/header contracts, both-tool proof, multi-turn observation and shared-service evidence, memory comparison, updated docs, removed semantic inventory, and remaining migration/artifact limitations. Do not bundle Librarian or unrelated generic MCP changes into this final Hatfield task.
