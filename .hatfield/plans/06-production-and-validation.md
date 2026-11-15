# 06 — Run Librarian in production mode and prove the complete MCP workflow

Status: TODO for remaining MCP/integration proof; preserve the already-running production deployment. Operational approval is required for any disruptive work.
Dependencies: implemented project/indexing facilities and plan 05’s frozen two-tool MCP contract (`memory_search` + `recall`, connection headers, required token). Current deployment/indexing docs supersede historical backend proposals.

## Requested outcome

Use Librarian’s existing production configuration and prove authenticated HTTP project-scoped search **and** recall end to end. Production registration/indexing already exist; do not reset them merely because this plan once described a fresh deployment. Do not claim a dev/test-container pass is production proof.

These plans do not authorize replacing an existing deployment, interrupting services, changing DNS/firewalls, or uploading private data. Inspect current deployment first and coordinate disruptive steps/secrets/host access with the user.

## Deployment work

- Use repository Castor production/Docker flow; discover current tasks rather than inventing raw Compose pipelines.
- Verify PHP/extensions, Symfony env/debug, migrations, persistent shared PostgreSQL + pgvector store, Messenger worker (`async` + `scheduler_default`), scheduler discovery interval, and approved CodeRank/BGE connectivity from app and worker containers (container localhost ≠ host model ports).
- Confirm HybridQuery date-filter pin (or successor official release) is present in the deployed image; see [docs/postgres-hybrid-filters.md](../../docs/postgres-hybrid-filters.md).
- Mount registered Hatfield roots read-only (`PROJECTS_HOST_PATH` / `PROJECTS_CONTAINER_PATH`); keep derived indexes writable only where needed.
- Secure `/_mcp`: required Bearer / `X-MCP-Token`, no URL-token fallbacks, HTTPS for non-loopback, protocol versions 2026-07-28 primary + 2025-11-25 fallback.
- One shared deployment for multiple Hatfield clients; record actual process topology for memory claims.
- Admin continues to show source health, live index status, and last successful index; operational readiness includes real embedding/search/recall requests.

## Required end-to-end proof

1. Confirm a populated registered project with nonzero OM counts and a usable committed index (no source writes).
2. Official PHP SDK over the production HTTP endpoint with auth + `X-Librarian-Project` (+ `X-Hatfield-Session` as needed). Confirm `tools/list` exposes exactly `memory_search` and `recall` with OM schemas (no project-path tool arg).
3. Ordinary useful searches with date bounds; inspect canonical IDs/session IDs. Use MCP `recall` for full evidence (obs/reflection supports/`events.jsonl`) against the same project context.
4. Several normal Hatfield turns with local observation still enabled; prove periodic Librarian discovery/index picks up new memories without manual reindex.
5. Recall a newly persisted observation locally (Hatfield) before the next index tick if needed for freshness contrast; after indexing, search and MCP-recall it. Distinguish local source availability from external index freshness.
6. With a second approved registration, switch only connection headers between requests and verify results stay within the selected project even when OM session IDs collide. No ambient MCP-session project state and no cross-project search mode.
7. Two concurrent clients against the same HTTP service; shared backend, bounded memory/work.
8. Where separately approved, verify persistence without full re-embedding. Do not restart production or induce failures on real data merely to validate the tools.
9. Confirm MCP search uses the existing project-scoped hybrid service (with date `where`/`params`) rather than a second retrieval stack. Preserve prior evaluation evidence; any quality comparison needs a consistent corpus and owner approval.

## Performance and memory report

Record revision, non-secret production config, source counts, model versions, process counts/concurrency, cold/index/search/recall latency, representative scans, and index sizes. Measure per-role and total PHP live/allocated/peak plus PSS/USS where available. Include PostgreSQL/pgvector container memory and persistent size. Report external embedding-server/GPU costs separately. Do not use forced GC, restarts, or reduced observation coverage to claim a quality-preserving memory fix.

## Safety and acceptance

- Automated tests use isolated fixtures; manual real-data checks use approved read-only sources and privacy-safe artifacts.
- No real settings replaced as fixtures, no source OM migrations, no destructive sync, no credential upload, no root/tagged process signals, no manual tmux kill-session/kill-server.
- Run repository Castor QA and the MCP journey, then the bounded real production workflow above. Record unavailable prerequisites as blockers, not passes.
- Both tools work against production with connection headers; results restricted to the selected project; canonical IDs/session IDs remain usable; errors/freshness visible; sources intact; owned test resources cleaned up.
- Supply a sanitized runbook: endpoint, absolute project path header value, exact revision, executed checks, memory/quality report, remaining limitations.

Backups remain separate; cross-project/global search is excluded. Plan 07 Hatfield client changes are not part of this deployment task.
