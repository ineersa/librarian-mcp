# Implementation roadmap

Current baseline in this repository:

- Symfony app with a single homepage
- Doctrine ORM + SQLite already configured
- Doctrine Messenger already configured with an `async` transport
- Security bundle installed, but only the default in-memory provider exists
- Dockerized FrankenPHP app already exposes `host.docker.internal`, which is useful for talking from the PHP container to a host service

## Core architecture decisions

### 1. Use a stable MCP tool set
Do **not** generate one MCP tool per library.

Instead, expose a small fixed set of tools such as:

- `libraries-list`
- `library-query`

That keeps the MCP server schema stable and means **adding a new library does not require reloading the MCP server**. New libraries become visible through data, not through new tool definitions.

### 2. Call Vera CLI directly from Symfony
For MVP, the simpler setup is:

- make `vera` available inside the FrankenPHP container
- call it via Symfony `Process`
- clone repos into `./data/libraries/<slug>/repo`
- run `vera index` and `vera search` inside that same container

Why this is the best first step:

- no extra bridge service to build or maintain
- no STDIO-to-HTTP wrapper needed yet
- Symfony already has a clean way to run CLI commands with `Process`
- `./data` is already shared with the project, so cloned repos and indexes stay local to the app

Important networking note:

- if Vera uses embedding/reranker APIs that currently point to `localhost`, those URLs must be changed for container runtime
- from inside the PHP container, host-local services should be reached via `host.docker.internal:<port>`

Later, if needed, this can evolve into either:

- a dedicated Vera sidecar with an HTTP wrapper
- or a proper Vera MCP integration

### 3. Keep browser auth and MCP auth separate
Use two auth mechanisms:

- **browser UI**: normal Symfony login/logout with session auth
- **MCP HTTP endpoint**: optional static token auth

That keeps MVP simple and avoids mixing interactive users with machine access.

## Stage order

1. [01-vera-bridge-foundation.md](./01-vera-bridge-foundation.md)
2. [02-backoffice-security.md](./02-backoffice-security.md)
3. [03-library-catalog.md](./03-library-catalog.md)
4. [04-ingestion-and-indexing-pipeline.md](./04-ingestion-and-indexing-pipeline.md)
5. [05-http-mcp-server.md](./05-http-mcp-server.md)
6. [06-post-mvp-library-lifecycle.md](./06-post-mvp-library-lifecycle.md)
7. [07-low-priority-exploration.md](./07-low-priority-exploration.md)

## MVP boundary

MVP should end when all of this works:

- admin can log in
- admin can create/edit/delete a library entry
- admin can submit a GitHub repository URL and basic Vera indexing settings
- repo is cloned into `./data/...`
- indexing runs asynchronously through Messenger
- library reaches a visible `ready` / `failed` status
- HTTP MCP server is exposed through Symfony MCP Bundle
- MCP clients can list libraries and query one specific library
- MCP endpoint can be protected by a simple token
- no extra bridge service is required for MVP

## Explicitly deferred from MVP

- multiple versions/branches per library
- pull/update workflows beyond a simple re-sync button
- full visual editor for all Vera settings
- Vera SQLite index inspection UI
- intent-based library discovery
