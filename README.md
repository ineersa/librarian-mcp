# Librarian MCP

Librarian MCP is a Symfony 8.2.0-BETA1 application that registers local Hatfield projects, discovers their observational-memory / session sources read-only, and builds Librarian-owned hybrid indexes for shared retrieval. This prerelease was explicitly owner-approved; see [framework compatibility](docs/framework-upgrade.md). The product path is project registration, source discovery, and derived indexing ([project sources](docs/project-sources.md), [indexing design](docs/indexing-design.md)).

It provides:

- an admin UI (EasyAdmin) to register/manage local projects
- async read-only source discovery (Messenger worker)
- project health, discovered source metadata, and index status in admin
- per-row Search memory over the committed active generation (admin UI; no date-window UI)
- HTTP MCP at `/_mcp` (setup-selectable token authentication; native `memory_search` + `recall`)

Registered project trees remain on the host/service mounts; Librarian only reads approved Hatfield settings/OM/session locations and stores derived registration/index state in its shared PostgreSQL + pgvector database (Doctrine metadata and official Symfony AI Postgres store tables). It does not clone repositories.

## Table of contents

- [What it actually is](#what-it-actually-is)
- [Production setup (Docker)](#production-setup-docker)
- [Project sources](#project-sources)
- [Key settings and what they mean](#key-settings-and-what-they-mean)
- [Development notes](#development-notes)
- [Testing](#testing)
- [Extra docs](#extra-docs)

---

## What it actually is

This is a shared local memory service for registered Hatfield projects:

- **Project catalog + health** (`pending` / `scanning` / `ready` / `error`)
- **Async read-only discovery** (so scans do not block web requests)
- **Derived hybrid indexing** (CodeRank 768-dim vectors via the official Symfony AI Postgres store + BGE reranker; stock weighted hybrid/`ts_rank`, not BM25/RRF)
- **Switchable model servers** — admin **Settings** picks, per model, an OpenAI-compatible API (default: host llama.cpp) or the optional built-in llama.cpp containers (`models` Compose profile, CPU), without a restart. See [built-in models](docs/setup.md#built-in-models-docker-without-host-llamacpp) and [model endpoints](docs/indexing-design.md#model-endpoints)
- **Stable project identity** (auto-increment ID survives rescans and explicit path rebinds)
- **Setup-selectable MCP token auth** (`MCP_AUTH_REQUIRED=1` by default; set `0` for trusted-network anonymous access; admin/login remain protected)
- **MCP tools** — `memory_search` and `recall` with OM schemas; project via `X-Librarian-Project` (not a tool arg). See [MCP contract](docs/MCP.md)

Architecture docs:

- [Architecture overview](docs/ARCHITECTURE.md)
- [Project sources](docs/project-sources.md)
- [Indexing design](docs/indexing-design.md)
- [MCP contract](docs/MCP.md)

---

## Production setup (Docker)

Detailed deployment notes are in [docs/server-deployment.md](docs/server-deployment.md).

### 1) Prepare env

```bash
cp .env.prod.local.dist .env.prod.local
```

Edit `.env.prod.local` and set at least:

- `APP_SECRET`
- `DEFAULT_URI`
- `MCP_ALLOWED_HOSTS` (CSV; include the external hostname without scheme or port)
- `HATFIELD_USER_HOME` (required logical Hatfield home context)
- `HATFIELD_APP_ROOT` when settings use `%kernel.project_dir%`
- `DATABASE_URL` (shared Doctrine + store Postgres)
- `CODERANK_URL`, `CODERANK_MODEL`, `CODERANK_ARTIFACT`
- `BGE_URL`, `BGE_MODEL`
- optional: `EMBEDDING_API_KEY`, `RERANKER_API_KEY` (only for API servers that need a key)
- optional: `COMPOSE_PROFILES=models` (and `LLAMA_THREADS`) to run the built-in embeddings/reranker containers, e.g. on a machine without host llama.cpp ([setup](docs/setup.md#built-in-models-docker-without-host-llamacpp))
- `INDEX_INTERVAL`, `LOCK_DSN`
- `MERCURE_JWT_SECRET`
- `MERCURE_PUBLISHER_JWT_KEY`
- `MERCURE_SUBSCRIBER_JWT_KEY`
- proxy trust settings (if behind nginx):
  - `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`
  - `SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port`

Generate secure values (example):

```bash
APP_SECRET="$(openssl rand -hex 32)"
MERCURE_KEY="$(openssl rand -base64 32)"

echo "APP_SECRET=$APP_SECRET"
echo "MERCURE_JWT_SECRET=$MERCURE_KEY"
echo "MERCURE_PUBLISHER_JWT_KEY=$MERCURE_KEY"
echo "MERCURE_SUBSCRIBER_JWT_KEY=$MERCURE_KEY"
```

Then put them in `.env.prod.local` and set:

- `DEFAULT_URI=https://your.domain`
- `MERCURE_PUBLIC_URL=https://your.domain/.well-known/mercure`

Configure `PROJECTS_HOST_PATH` in `.env` (or exported process environment), not the container-only `.env.prod.local`; alternatively declare source binds in ignored `compose.sources.yaml`. OM database directories need writable mounts for SQLite WAL/SHM sidecars; database connections remain `mode=ro`. See [source mounts](docs/project-sources.md#docker-source-mounts).

> For the default HS256 setup in this repo, keep `MERCURE_JWT_SECRET` equal to `MERCURE_PUBLISHER_JWT_KEY`.

### 2) One command: build + start + migrate + create admin

> This uses Castor tasks (project standard) and keeps restart behavior from compose (`restart: unless-stopped`).

```bash
vendor/bin/castor prod:up \
  && vendor/bin/castor prod:console -- doctrine:migrations:migrate --no-interaction \
  && vendor/bin/castor prod:console -- app:user:create-admin admin@example.com --password='change-me-now'
```

### 3) Restart/update flow

```bash
vendor/bin/castor prod:restart
```

If you changed Dockerfiles/deps/env and want a rebuild:

```bash
vendor/bin/castor prod:down && vendor/bin/castor prod:up
```

### 4) Check services/logs

```bash
vendor/bin/castor prod:ps
vendor/bin/castor prod:logs
```

---

## Project sources

See [docs/project-sources.md](docs/project-sources.md) for the registration contract, Hatfield settings overlay, path mappings, OM schema support, and operational mounts.

Summary:

- Register a project by entering its absolute, service-visible directory path in admin
- Unique case-insensitive trimmed names, canonical directories, and normalized Git origins
- Registration is create/delete only (no edit); uniqueness is by name, canonical directory, and normalized Git origin
- Discovery is read-only; removing a registration deletes owned derived indexes but never source trees, `.hatfield`, OM databases, or journals
- `ready` means sources were discovered, not indexed; source journal counts are not an index backlog
- Admin Search memory operates on Librarian-owned derived data (no date-window UI). MCP exposes `memory_search` / `recall`; see [MCP contract](docs/MCP.md) and [HybridQuery date filters](docs/postgres-hybrid-filters.md)
- Design details: [indexing design](docs/indexing-design.md); validation status: [indexing validation](docs/indexing-validation.md)

---

## Key settings and what they mean

### Runtime / deployment

- `APP_HTTP_BIND`, `APP_HTTP_PORT`
  Host bind/port for the app container (from `compose.prod.yaml`).
- `SERVER_NAME`
  Caddy/FrankenPHP server name mode.
- `DOCKER_DNS_PRIMARY`, `DOCKER_DNS_SECONDARY`
  DNS override for container resolution reliability.
- `PROJECTS_HOST_PATH`
  Host path mounted read-only into the service as `/projects` (default `.`); SQLite connections remain `mode=ro`.

### Symfony / app

- `APP_ENV` (`prod` in production)
- `APP_SECRET`
  Required app secret.
- `DEFAULT_URI`
  Canonical base URL.
- `HATFIELD_USER_HOME`
  Required logical Hatfield user-home context for settings/path resolution.
- `HATFIELD_APP_ROOT`
  Optional unless settings use `%kernel.project_dir%`.
- `HATFIELD_PATH_MAPPINGS`
  Optional JSON longest-prefix host→service path mappings.
- `DATABASE_URL`
  Shared PostgreSQL + pgvector for Doctrine metadata and official store tables.
- `CODERANK_URL`, `CODERANK_MODEL`
  Default CodeRankEmbed API endpoint and model alias. Used in API mode when the Settings URL/model fields are empty; a saved Settings value or Built-in mode overrides them at runtime. `CODERANK_MODEL` is also the model label in the index generation signature, so changing it in env rebuilds indexes (the Settings model field does not).
- `CODERANK_ARTIFACT`
  Immutable GGUF SHA-256 of the embedding model. Part of the index generation signature; changing it rebuilds indexes. Not overridden by Settings.
- `BGE_URL`, `BGE_MODEL`
  Default BGE reranker API endpoint/model (Voyage request client used only as protocol adapter), overridable in Settings like the embeddings values.
- `EMBEDDING_API_KEY`, `RERANKER_API_KEY`
  Optional bearer keys for API-mode servers. Read only from env (never stored in the database; Settings shows set / not set) and never sent to the built-in containers. Changing one needs a container recreate.
- `COMPOSE_PROFILES=models`, `LLAMA_THREADS`
  Start the built-in `embeddings` / `reranker` llama.cpp containers (private network, no host ports; GGUFs downloaded on first start). Settings offers **Built-in** only while they respond.
- `INDEX_INTERVAL`, `LOCK_DSN`
  Automatic source-discovery/index-check interval (`CallbackMessageProvider` yields discovery jobs; successful scans queue incremental indexing) and shared lock DSN.

### Mercure

- `MERCURE_PUBLIC_URL`
  Browser-facing Mercure URL.
- `MERCURE_JWT_SECRET`
  Symfony Mercure bundle secret.
- `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`
  Hub signing keys used by Caddy/Mercure.

### Security / MCP

- MCP endpoint: `/_mcp`
- `MCP_AUTH_REQUIRED` — `1` (default) requires a valid token; `0` allows tokenless requests, including `ensure_project`. This is server configuration, not a client-header bypass. Anonymous mode permits registration and exposes registered-project memory to anyone who can reach the endpoint; use only on a trusted network. Supplied credentials are still validated.
- Auth headers (required when `MCP_AUTH_REQUIRED=1`; URL query-token fallbacks removed):
  - `Authorization: Bearer <token>`
  - `X-MCP-Token: <token>`
- Connection header (not a tool arg): `X-Librarian-Project` (absolute root, canonicalized by the server). `ensure_project` takes `{}`, returns an existing registration unchanged or creates it using the basename and queues normal asynchronous discovery. No `.hatfield`/initialized OM prerequisite, source writes, synchronous indexing, or readiness polling. Success means registration exists, not indexing is complete. Search/recall still require registration; recall takes only `id`, derives the source session, and returns full IDs/content when a prefix is ambiguous.
- Admin/login remain protected (`ROLE_ADMIN`)
- Tokens are stored hashed (SHA-256) when used
- `MCP_ALLOWED_HOSTS` — CSV allow-list for MCP HTTP hosts (default `localhost,127.0.0.1,php,server,192.168.2.38`); override with production external hostnames
- Bundle config: `mcp.servers.librarian` with an explicit registry (MCP bundle 0.15)
- Public tools: `memory_search`, `recall` (OM parameter schemas). Details: [docs/MCP.md](docs/MCP.md)

### Storage

- Shared PostgreSQL + pgvector database for app metadata and generation-scoped store tables
- Scheduler freshness via Messenger `scheduler_default` (`INDEX_INTERVAL`, default 60s)
- Daily Google Cloud Storage backups of PostgreSQL, Hatfield and Claude data, tracked in admin **Cron runs** ([docs/backups.md](docs/backups.md)). **Settings › Backups › Scheduled daily backups** turns the scheduled run off (e.g. on a laptop without GCS credentials); **Run backup now** still works.
- Registered project sources stay on the mounted project tree (`/projects` and optional `compose.sources.yaml` overlays)
- Do not commit private project settings, OM databases, or session journals as fixtures

---

## Development notes

Prefer the Composer-locked host entry point `vendor/bin/castor`. Discover tasks with `vendor/bin/castor list`: `tooling:prepare`, `app:composer`, `app:console`, `cs:fix`, `qa:static`, `test`, `check`, and private browser/development stack tasks.

- Bootstrap tooling:

  ```bash
  vendor/bin/castor tooling:prepare
  ```

- Migrate / console:

  ```bash
  vendor/bin/castor app:console -- doctrine:migrations:migrate --no-interaction
  ```

- Quality and tests:

  ```bash
  vendor/bin/castor cs:fix
  vendor/bin/castor qa:static
  vendor/bin/castor test
  ```

- Task catalog/details:

  - [Castor command guide](docs/castor.md)
  - [Development setup](docs/setup.md)
  - `vendor/bin/castor list`

- Do not run host PHP/Composer directly for project operations once the tooling runtime exists.

### Framework upgrade gate

On 2026-11-12 the owner approved **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) with package-scoped stability allowances. The lockfile selects core 8.2.0-BETA1, MCP 0.15 / SDK 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, and UX Stimulus/Turbo 3.7. See [framework compatibility](docs/framework-upgrade.md) for dependency decisions, [indexing design](docs/indexing-design.md) for the architecture, and [indexing validation](docs/indexing-validation.md) for recorded checks.

---

## Testing

See [TESTING.md](TESTING.md) for Playwright policy, isolated browser stack (`LIBRARIAN_BROWSER_URL`), and Castor QA commands. This README does not claim validation has passed.

## Extra docs

- [Agent / operations guidance](AGENTS.md)
- [Testing](TESTING.md)
- [Development setup](docs/setup.md)
- [Server deployment](docs/server-deployment.md)
- [Castor command guide](docs/castor.md)
- [Project sources](docs/project-sources.md)
- [Indexing design](docs/indexing-design.md)
- [Indexing validation](docs/indexing-validation.md)
- [Mercure notes](docs/mercure.md)
- [Architecture overview](docs/ARCHITECTURE.md)
- [MCP contract](docs/MCP.md)
