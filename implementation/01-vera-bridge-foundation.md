# Stage 1 — Vera CLI foundation

## Goal
Make `vera` callable from the Symfony app with the smallest possible runtime setup.

## Decision
For MVP, do **not** build an HTTP bridge.
Use Symfony `Process` to execute the `vera` CLI directly from the FrankenPHP container.

## Why a separate Vera container is not enough by itself
A container in the same Compose network only helps if it exposes a network protocol.

`vera` is a CLI program, so Symfony cannot call a separate `vera` container just because both containers are running.
Without an HTTP wrapper, gRPC service, or MCP server transport exposed over the network, FrankenPHP has nothing to connect to.

That means there are only three realistic options:

1. install `vera` inside the PHP container and call it with `Process` **(recommended for MVP)**
2. run a separate service that wraps `vera` and exposes HTTP
3. mount the Docker socket and shell out to `docker exec` from the PHP container *(not recommended)*

## Recommended MVP runtime

### Inside the PHP container
Install:

- `git`
- `vera`

Then Symfony can execute commands such as:

- `git clone ... /app/data/libraries/<slug>/repo`
- `vera index /app/data/libraries/<slug>/repo`
- `vera search "..." --json`

This fits the current Docker setup well because:

- the app already runs in Docker
- the project is mounted into `/app` in dev
- `./data` is already gitignored and visible both from host and container

## Vera API endpoint note
You raised the important issue correctly:
if Vera inside the container calls embedding/reranker APIs configured as `localhost`, it will fail, because inside Docker `localhost` means the container itself.

So for MVP we should change those endpoints to container-reachable URLs:

- host service -> `http://host.docker.internal:<port>`
- sibling Compose service -> `http://<service-name>:<port>`

The repo already maps `host.docker.internal`, so this is compatible with the current setup.

## Scope

### Docker work
Update the PHP image so `vera` is available in both dev and prod-like environments.

Possible approaches:

- install the Vera binary during image build
- or copy the binary from the Vera container image into the FrankenPHP image

If you want to use `ghcr.io/ineersa/vera`, the most useful MVP usage is likely:

- **copy the binary from that image into the PHP image**, not run it as a standalone sidecar yet

## Symfony-side work
Create a small service, for example `VeraCli`, responsible for:

- building safe command arguments
- executing `git` / `vera` commands with Symfony `Process`
- enforcing timeouts
- capturing stdout/stderr
- converting CLI failures into structured exceptions

This service should expose focused methods, for example:

- `cloneRepository(Library $library)`
- `indexLibrary(Library $library)`
- `searchLibrary(Library $library, string $query, array $filters = [])`

Do not pass raw shell strings around the app.

## Important implementation rule
Symfony should resolve the library to a known local path like:

- `/app/data/libraries/<slug>/repo`

Do not accept arbitrary filesystem paths from user input.

## Suggested env/config knobs
Add app-level configuration for:

- Vera binary path if needed, e.g. `VERA_BINARY=vera`
- default git clone base dir, e.g. `data/libraries`
- Vera API endpoint env vars for embeddings/reranker using container-reachable URLs

## Future evolution
After MVP, this can evolve into:

- Vera MCP integration
- or a dedicated Vera sidecar with its own API

But we do not need that complexity now.

## Acceptance criteria

- `vera` runs successfully inside the PHP container
- Symfony can execute `vera index` through `Process`
- a test repo can be cloned into `data/libraries/<slug>/repo`
- a simple `vera search --json` works for that library
- CLI failures are converted into readable application errors
