# Librarian MCP

Librarian MCP is a Symfony 8.2 application (upgrade in progress; see AGENTS.md) that turns Git repositories into searchable MCP libraries.

It provides:

- an admin UI (EasyAdmin) to register/manage libraries
- an async indexing pipeline (Messenger worker)
- a stable MCP tool surface for clients:
  - `search-libraries`
  - `semantic-search`
  - `grep`
  - `read`

Under the hood it clones repositories, indexes them with Vera, and exposes ready libraries through `/_mcp`.

## Table of contents

- [What it actually is](#what-it-actually-is)
- [Production setup (Docker)](#production-setup-docker)
- [Vera and our workflow tuning](#vera-and-our-workflow-tuning)
- [Key settings and what they mean](#key-settings-and-what-they-mean)
- [Development notes](#development-notes)
- [Testing](#testing)
- [Extra docs](#extra-docs)

---

## What it actually is

This is **not** just a static MCP wrapper.
It is a full service with:

- **Catalog + state model** (draft/queued/indexing/ready/failed)
- **Async sync/index jobs** (so indexing does not block web requests)
- **Read safety sandbox** (`read` only allows whitelisted text files)
- **Token-based MCP auth** (`ROLE_MCP` required)
- **Metadata corpus** for better `search-libraries` ranking

Architecture docs:

- [Architecture overview](docs/ARCHITECTURE.md)
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

## Vera and our workflow tuning

- Original Vera project: https://github.com/lemon07r/Vera
- This project uses Vera through Symfony service wrappers and a production workflow tuned for library indexing.

### Where Vera config lives

- `docker/vera/config.json`
- `docker/vera/credentials.json`
- `docker/vera/README.md`

The directory is mounted into containers as `~/.vera`.

### What is tuned here

Current config is API-backend oriented and optimized for predictable indexing/retrieval in this app:

- `backend: "api"` with separate embedding/reranker/completion endpoints
- indexing defaults include practical excludes (e.g. `.git`, `node_modules`, `vendor`, `dist`, etc.)
- retrieval has reranking enabled with bounded candidate sizes
- embedding batch/concurrency is conservative for stable container workloads

Per-library overrides are supported in admin via `veraConfig`:

- `excludePatterns` (extra `--exclude` globs)
- `noDefaultExcludes` (maps to `--no-default-excludes`)

These are applied when running `vera index` for that library.

---

## Key settings and what they mean

### Runtime / deployment

- `APP_HTTP_BIND`, `APP_HTTP_PORT`  
  Host bind/port for the app container (from `compose.prod.yaml`).
- `SERVER_NAME`  
  Caddy/FrankenPHP server name mode.
- `DOCKER_DNS_PRIMARY`, `DOCKER_DNS_SECONDARY`  
  DNS override for container resolution reliability.

### Symfony / app

- `APP_ENV` (`prod` in production)
- `APP_SECRET`  
  Required app secret.
- `DEFAULT_URI`  
  Canonical base URL.

### Mercure

- `MERCURE_PUBLIC_URL`  
  Browser-facing Mercure URL.
- `MERCURE_JWT_SECRET`  
  Symfony Mercure bundle secret.
- `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`  
  Hub signing keys used by Caddy/Mercure.

### Security / MCP

- MCP endpoint: `/_mcp`
- Auth headers:
  - `Authorization: Bearer <token>`
  - `X-MCP-Token: <token>`
- User must have `ROLE_MCP`
- Tokens are stored hashed (SHA-256)
- `MCP_ALLOWED_HOSTS` — CSV allow-list for MCP HTTP hosts (default `localhost,127.0.0.1,php,server,192.168.2.38`); override with production external hostnames
- Bundle config: `mcp.servers.librarian` with an explicit tool registry (MCP bundle 0.15)

### Storage

- Library clones + indexes: `data/libraries/...` (dev) / `data-prod/...` (prod stack)
- Metadata corpus: `<libraryDataDir>/mcp-metadata-corpus`

---

## Development notes

Prefer the Composer-locked host entry point `vendor/bin/castor`. Discover live task names with `vendor/bin/castor list` — the framework-upgrade runtime child replaces legacy `dev:*` wrappers with `tooling:prepare`, `app:composer`, `app:console`, `cs:fix`, `qa:static`, `test`, `check`, and browser-stack tasks.

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

On 2026-11-12 the owner approved **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) with package-scoped stability allowances. Main resolved core 8.2.0-BETA1 pins, MCP 0.15 / sdk 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, and UX Stimulus/Turbo 3.7. Lockfile/runtime smoke proof and remaining test-gap notes are owned by main — this documentation update does **not** claim those checks passed.

---

## Testing

See [TESTING.md](TESTING.md) for Playwright policy, isolated browser stack (`LIBRARIAN_BROWSER_URL`), and Castor QA commands. This README does not claim tests have passed.

## Extra docs

- [Agent / operations guidance](AGENTS.md)
- [Testing](TESTING.md)
- [Development setup](docs/setup.md)
- [Server deployment](docs/server-deployment.md)
- [Castor command guide](docs/castor.md)
- [Mercure notes](docs/mercure.md)
- [Architecture overview](docs/ARCHITECTURE.md)
- [MCP contract](docs/MCP.md)
