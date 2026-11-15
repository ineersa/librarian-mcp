# Librarian: shared Hatfield memory service

Status: planning/docs track. Created 2026-11-12; revised 2026-11-15 for the approved plan 05 contract (two native tools, connection headers, required token, HybridQuery date filters). These plans do not by themselves authorize production resets, secret commits, or Hatfield edits.

## Goal and agreed boundary

Repurpose Librarian into one shared HTTP MCP memory service for registered local Hatfield projects. Hatfield sessions currently pay semantic indexing/search costs in their own workers; centralizing that work avoids multiplying those costs by session count.

- **Hatfield OM owns canonical memory:** observing, reflecting, coverage, compaction, local persistence, and session journals continue locally.
- **Librarian owns derived search and remote recall:** project registration, periodic incremental indexing, project-scoped hybrid search, index status, and MCP `memory_search` / `recall`.
- **Shared means shared:** one deployed HTTP service, not a new stdio MCP process per Hatfield session.
- **Librarian never observes or reflects on Hatfield’s behalf.** It reads canonical data without migrating or mutating Hatfield databases.
- **Project-scoped only.** Project root is selected via connection header `X-Librarian-Project` (absolute registered canonical root), not a tool argument and not ambient MCP-session state. No cross-project/global search.
- **Tools:** exactly `memory_search` and `recall` with OM’s current parameter schemas. Final Hatfield integration (plan 07) uses the official PHP MCP SDK client against that contract and removes Hatfield’s local semantic implementation once validated.
- With no Librarian configured, Hatfield retains regular local literal search and local recall.

## Historical approved retrieval baseline — 2026-11-12

Implementation selected PostgreSQL/pgvector and the official Symfony AI Postgres store (see current `AGENTS.md` and `docs/indexing-design.md`). The historical Chroma + SQLite FTS proposal below is evaluation provenance only.

The owner approved keeping **CodeRankEmbed + lexical ranking + BGE reranking**. Stock Postgres hybrid uses weighted similarity + `ts_rank` (not BM25/RRF). Plan 05 adds a temporary Composer-pinned HybridQuery `where`/`params` fork for honest date filtering; see [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md).

## Implementation order

| Order | Plan | Depends on | Boundary |
|---|---|---|---|
| 01 | [Symfony 8.2 and latest Symfony AI](01-framework-upgrade.md) | None | Upgrade infrastructure without mixing in the domain rewrite |
| 02 | [Completed model/retrieval evaluation](02-embedding-model-evaluation.md) | Evaluation complete | Preserve evidence; historical storage proposal superseded |
| 03 | [Registered projects and read-only Hatfield sources](03-projects-and-hatfield-sources.md) | 01 | Replace Git-library workflow with read-only project registration |
| 04 | [Incremental Symfony AI hybrid indexing](04-symfony-ai-indexing.md) | Implemented | Existing PostgreSQL/pgvector hybrid service |
| 05 | [Native memory_search + recall over HTTP MCP](05-memory-mcp-tools.md) | 03/04 + HybridQuery filter pin | Two OM-schema tools; connection headers; required token |
| 06 | [Remaining production MCP/integration proof](06-production-and-validation.md) | 05 | Preserve existing deployment; prove both tools live |
| 07 | [Hatfield OM Librarian bridge and semantic removal](07-hatfield-integration.md) | 05 frozen, 06 validated | **Final Hatfield change/review** |

[Deferred backups](90-deferred-backups-and-global-search.md) remain separate. Cross-project/global search in that historical filename has been withdrawn.

## Shared invariants

1. Canonical OM/session data remain in the project; derived indexes live in Librarian-owned PostgreSQL outside user source trees.
2. Never delete, clone into, migrate, repair, rewrite, or normalize registered source directories as an indexing operation.
3. Resolve actual Hatfield settings for custom OM/session locations. Do not recursively index secrets, credentials, or unrelated `.hatfield` artifacts.
4. Every MCP call resolves project from `X-Librarian-Project` and stays within that registration throughout retrieval/recall.
5. Search indexes retained observations/reflections and returns original canonical IDs/session IDs. Recall hydrates full OM evidence from read-only sources. Raw session semantic indexing is not initial scope.
6. Indexing is incremental, bounded, restartable, and project-serialized. Search does not synchronously rebuild an entire project index.
7. Search output distinguishes stale/partial indexing and bounded-candidate truncation. Similarity scores are not probabilities.
8. Canonical session journal is `events.jsonl`; keep `session_id === run_id`.
9. Keep model bodies, observation text, prompts, credentials, and tokens out of routine logs and committed fixtures.
10. Exact public schemas beyond agreed tool/header names are documented in plan 05 / `docs/MCP.md` before coding.

## Review and handoff expectations

Each implementation should provide its target revision, focused diff, schema/config decisions, Castor validation commands/results, blockers, and remaining limitations. Keep dependency upgrades, domain rewrite, MCP contract, deployment, and Hatfield integration reviewable separately.

Before deleting old Hatfield semantic code/docs, preserve the question set and sanitized evaluation provenance in Librarian. Do not commit private databases, source journals, endpoint responses, credentials, or model binaries with these plans.
