# Librarian MCP

Librarian MCP is a Symfony 8 application that turns Git repositories into searchable MCP libraries.

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
castor prod:up \
  && castor prod:console "doctrine:migrations:migrate --no-interaction" \
  && castor prod:console "app:user:create-admin admin@example.com --password='change-me-now'"
```

### 3) Restart/update flow

```bash
castor prod:restart
```

If you changed Dockerfiles/deps/env and want a rebuild:

```bash
castor prod:down && castor prod:up
```

### 4) Check services/logs

```bash
castor prod:ps
castor prod:logs
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

### Storage

- Library clones + indexes: `data/libraries/...` (dev) / `data-prod/...` (prod stack)
- Metadata corpus: `<libraryDataDir>/mcp-metadata-corpus`

---

## Development notes

- Setup:

  ```bash
  castor dev:setup
  castor dev:bootstrap
  ```

- Run app stack:

  ```bash
  castor dev:up
  ```

- Run messenger consumer in another terminal:

  ```bash
  castor dev:messenger-consume
  ```

- Useful tasks:

  - `castor dev:test`
  - `castor dev:phpstan`
  - `castor dev:cs-fix`
  - `castor dev:quality`

- For task catalog/details:

  - [Castor command guide](docs/castor.md)
  - `castor list dev`
  - `castor list prod`

- Do not run host PHP/Composer directly for project operations; use Castor tasks.

---

## Extra docs

- [Development setup](docs/setup.md)
- [Server deployment](docs/server-deployment.md)
- [Mercure notes](docs/mercure.md)
- [Architecture overview](docs/ARCHITECTURE.md)
- [MCP contract](docs/MCP.md)
