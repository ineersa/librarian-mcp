# Librarian MCP

Librarian MCP is a Symfony 8.2.0-BETA1 application that registers local Hatfield projects and discovers their observational-memory / session sources read-only for later shared retrieval. This prerelease was explicitly owner-approved; see [upgrade notes](docs/framework-upgrade.md). The current product path is project registration and source discovery ([project sources](docs/project-sources.md)); that supersedes the earlier Git/Vera library workflow described historically in the upgrade notes.

It provides:

- an admin UI (EasyAdmin) to register/manage local projects
- async read-only source discovery (Messenger worker)
- project health and discovered source metadata in admin
- HTTP MCP at `/_mcp` (token optional; tools arrive in plan 05)

Under the hood it does **not** clone repositories or run Vera. Registered project trees remain on the host/service mounts; Librarian only reads approved Hatfield settings/OM/session locations and stores derived registration state in its own database.

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
- **Stable project identity** (auto-increment ID survives rescans and explicit path rebinds)
- **Optional MCP token auth** (admin/login remain protected; supplied tokens are still validated)
- **No public MCP tools yet** — `tools/list` is empty until plan 05

Architecture docs:

- [Architecture overview](docs/ARCHITECTURE.md)
- [Project sources](docs/project-sources.md)
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
- `PROJECTS_HOST_PATH` / `PROJECT_BROWSE_ROOT` for the mounted project tree and picker start path
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

## Project sources

See [docs/project-sources.md](docs/project-sources.md) for the registration contract, Hatfield settings overlay, path mappings, OM schema support, and operational mounts.

Summary:

- Register a service-visible project directory from admin (directory picker starts at `PROJECT_BROWSE_ROOT`)
- Unique case-insensitive trimmed names, canonical directories, and normalized Git origins
- Explicit admin path changes rebind the same project ID; they do not merge by name/origin
- Discovery is read-only; removing a registration never deletes source trees, `.hatfield`, OM databases, or journals
- `ready` means sources were discovered, not indexed; last index remains “not yet”; source journal counts are not an index backlog
- Periodic indexing/scheduling is deferred to plan 04

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
  Host path mounted read-only into the service as `/projects` (default `.`).
- `PROJECT_BROWSE_ROOT`  
  Starting directory for the admin directory picker (not a security allowlist).

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

### Mercure

- `MERCURE_PUBLIC_URL`  
  Browser-facing Mercure URL.
- `MERCURE_JWT_SECRET`  
  Symfony Mercure bundle secret.
- `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`  
  Hub signing keys used by Caddy/Mercure.

### Security / MCP

- MCP endpoint: `/_mcp`
- Auth headers (optional; when supplied they are validated):
  - `Authorization: Bearer <token>`
  - `X-MCP-Token: <token>`
- Admin/login remain protected (`ROLE_ADMIN`)
- Tokens are stored hashed (SHA-256) when used
- `MCP_ALLOWED_HOSTS` — CSV allow-list for MCP HTTP hosts (default `localhost,127.0.0.1,php,server,192.168.2.38`); override with production external hostnames
- Bundle config: `mcp.servers.librarian` with an explicit registry (MCP bundle 0.15)
- No public tools until plan 05 (`tools/list` is empty)

### Storage

- Librarian app DB / derived registration state: `data/app` (dev) / production data mount
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

On 2026-11-12 the owner approved **Symfony 8.2.0-BETA1** (stable 8.2 not yet available) with package-scoped stability allowances. The lockfile selects core 8.2.0-BETA1, MCP 0.15 / SDK 0.9, Mate 0.15 stable, EasyAdmin 5.10, Doctrine ORM 3.7.6, and UX Stimulus/Turbo 3.7. See [upgrade notes](docs/framework-upgrade.md) for compatibility decisions and recorded checks. Product behavior after plan 03 is project registration / source discovery, not the historical Git/Vera library path.

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
- [Mercure notes](docs/mercure.md)
- [Architecture overview](docs/ARCHITECTURE.md)
- [MCP contract](docs/MCP.md)
