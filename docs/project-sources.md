# Local projects, Hatfield sources, and derived indexing

## Current product boundary

Librarian registers **local directories**, not GitHub libraries. It discovers Hatfield OM/session metadata read-only, then builds Librarian-owned hybrid indexes from retained observations/reflections. It does not boot Hatfield, load extension PHP, run source migrations, clone repositories, or call Vera/Vektor/Gemma paths.

- **Sources discovered** (`ready`) means discovery succeeded, not that memories are indexed.
- **Index now / Search memory** are admin actions over derived data. Search never synchronously ingests or rebuilds a project.
- Public MCP memory tools remain **plan 05**. Production cutover/migration authorization remains **plan 06**.
- Design, collection layout, checkpoint rules, and pending evidence live in [indexing-design.md](indexing-design.md).

## Identity and removal

- `Project.id` is an auto-increment integer, stable across scans, renaming, and explicit administrator path rebinding.
- Trimmed, case-insensitive display names must be unique. Canonical directories and normalized Git origins must also be unique. A second checkout with the same origin is rejected, not silently merged. Non-Git/no-origin directories are supported.
- Git inspection failures are registration errors, not evidence of a non-Git/no-origin directory. For mounted worktrees, make the linked worktree and common Git metadata available read-only at the paths recorded in `.git`.
- Editing the directory rebinds the **same registration ID**. Nothing infers identity from a basename, session number, name, or origin.
- A scan publishes results only if the registration still refers to its scanned directory. Filesystem discovery runs outside the short Doctrine write transaction.
- Deletion removes the Librarian registration and **owned derived indexes** (Chroma collections / lexical store files / generation rows). It never removes the project directory, `.hatfield`, OM database, journals, or external/symlink destinations.
- Legacy `libraries` rows remain excluded from ORM ownership. Generated migrations add Librarian-owned tables only.

## Source settings contract

Discovery resolves settings with Hatfield YAML overlay semantics:

1. Source defaults: `.hatfield/extensions-data/observational-memory/om.sqlite` and `.hatfield/sessions` under the selected project.
2. Explicit Hatfield user-home `.hatfield/settings.yaml`.
3. Project `.hatfield/settings.yaml`.

Associative maps merge recursively; lists/scalars/null replace. Missing optional settings files are valid; malformed YAML is not. OM must be enabled as `Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension`.

- OM override: `observational_memory.storage.database`.
- Session override: `sessions.path` with `~` / `%kernel.project_dir%` expansion only.
- `HATFIELD_USER_HOME` is required; `HATFIELD_APP_ROOT` when `%kernel.project_dir%` is used; optional `HATFIELD_PATH_MAPPINGS` JSON longest-prefix maps.
- `PROJECT_BROWSE_ROOT` is the picker start path only. There is no security root allowlist for this local single-admin utility.

Supported OM schema versions remain through `20261021_006_semantic_source_revisions`. Source change journal rows are **hints**, not a durable independent consumer feed, and are never consumed.

## Derived indexing (plan 04)

Approved baseline after plan 02:

- CodeRankEmbed explicit **768-dim** vectors (existing local endpoint)
- Private persistent **Chroma** image tag **1.0.21** with PHP client **1.0.0** and Symfony AI **0.15** bridges
- SQLite FTS5/BM25 lexical branch
- Native Symfony RRF **k60** (vector100 / lexical100 / fused200)
- Local BGE rerank batches **8**, 768-character truncation, floor **-4**

Notes:

- Chroma `/version` may report **1.0.0** while the image tag is **1.0.21** — do not conflate them.
- Vectors are sent explicitly; do not use a Chroma default embedding function. Cosine space is configured through supported collection/client facilities.
- Native Generic platform + Voyage request client are used only as the **local BGE protocol** adapter. No hosted Voyage provider is selected.
- No Vektor, Vera, or EmbeddingGemma path remains in the replacement pipeline.
- Journals support exact recall later; they are not embedded in the initial corpus.

Admin detail shows latest/active generation status, source/committed revision, parent/chunk counts, sanitized errors, and last successful index time. Pending/stale status is honest: discovery is not indexing. Detail uses the existing bounded meta refresh while work is in progress.

Config surface:

- `CODERANK_URL`, `CODERANK_MODEL`, `CODERANK_ARTIFACT`
- `BGE_URL`, `BGE_MODEL`
- `CHROMA_HOST`, `CHROMA_PORT`
- `INDEX_STORAGE_DIR`, `INDEX_INTERVAL`, `LOCK_DSN`

Worker consumes Messenger `async` and `scheduler_default`. Chroma stays on the private network with **no public host port**. Browser derived indexes live under ignored `var/browser-live/indexes`; Chroma uses an owned volume.

## Docker source mounts

`PROJECTS_HOST_PATH` defaults to this checkout (`.`) and is bound read-only at `/projects` in dev/production. Web and Messenger consumer need the same source mounts/env. Optional ignored `compose.sources.yaml` is layered by Castor for **dev/prod only**, never for the isolated browser stack. Production consumer service name is `messenger` (not `worker`).

EasyAdmin uses full-page navigation and the separate `admin` AssetMapper entry so the public Tailwind reset stays out of admin. Login uses EasyAdmin's built-in dark template.

## Validation evidence

Saved coverage remains **one** Projects journey: register/discover → Index now → search a public synthetic observation → ordinary harness-owned fixture content/revision update → Index now → updated search → stable-ID rebind/delete with source-hash checks. Fixture mutation is harness-owned and restored; app code must not mutate sources.

Observed so far (not acceptance):

- Isolated-storage migration creates `index_generations` / `indexed_memories` and schema validation passed there.
- Indexing capabilities checked native cosine, explicit vectors, upsert/update/delete, native project/date filters, and real CodeRank 768 + BGE.
- Static gate currently passes after the lazy Chroma client fix.
- Saved browser journey is still under selector diagnosis; populated corpus profile, frozen 50 + 5-identifier comparison, and restart persistence are **not** measured yet.

Do not claim plan 04 acceptance, publish invented counts/metrics, or imply production services were cut over. Prefer [indexing-design.md](indexing-design.md) for pending evidence and [TESTING.md](../TESTING.md) for suite policy.
