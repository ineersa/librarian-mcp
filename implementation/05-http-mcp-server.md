# Stage 5 — HTTP MCP server

## Goal
Expose indexed libraries through a simple HTTP MCP server using Symfony MCP Bundle.

Reference: Symfony MCP Bundle supports HTTP transport, which is enough for this MVP.

## Package and config
Add:

- `symfony/mcp-bundle`
- route configuration for MCP bundle
- `config/packages/mcp.yaml`

Recommended MVP config:

- enable HTTP transport
- keep STDIO disabled for now
- mount endpoint under `/_mcp`

## Keep the tool surface fixed
Do not create one MCP tool per library.
Use a small stable tool set.

### MVP tools

#### `libraries-list`
Purpose:
- list available libraries
- support partial search by library name

Inputs:
- `name` (optional string)

Behavior:
- query database with partial name matching
- return only libraries in `ready` state
- include `id`, `slug`, `name`, `description`, `lastIndexedAt`

#### `library-query`
Purpose:
- run a Vera query within one specific library

Inputs:
- `library` (id or slug)
- `query` (required)
- `limit` (optional)
- optional pass-through filters:
  - `lang`
  - `path`
  - `type`
  - `scope`

Behavior:
- resolve selected library from database
- ensure it is `ready`
- call the local `VeraCli` service for that library only
- return structured search results

## Authentication model
MCP auth should be optional and independent from browser login.

### Proposed env var
- `MCP_AUTH_TOKEN=`

Behavior:
- empty value => auth disabled
- non-empty value => token required on `/_mcp`

### Accepted formats
Support both:

- `Authorization: Bearer <token>`
- `X-MCP-Token: <token>`

## Security implementation
Use a dedicated firewall/authenticator or a narrow request listener only for `/_mcp`.
Do not require session login for MCP clients.

## Logging
Add enough structured context to diagnose:

- incoming MCP tool name
- selected library id/slug
- Vera command latency
- failures

## Important outcome
With this design, **new libraries do not require MCP server reload**.
Only database rows change.
The exposed MCP capabilities stay the same.

## Acceptance criteria

- MCP endpoint is reachable over HTTP
- optional token auth works
- client can list libraries
- client can query a specific indexed library
- tool set stays stable as libraries are added or removed
