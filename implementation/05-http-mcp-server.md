# Stage 5 — HTTP MCP server

## Goal
Expose indexed libraries through a simple HTTP MCP server using Symfony MCP Bundle.

Reference: Symfony MCP Bundle supports HTTP transport, which is enough for this stage.

## Package and config
Add:

- `symfony/mcp-bundle`
- route configuration for MCP bundle
- `config/packages/mcp.yaml`

Config for this stage:

- enable HTTP transport
- keep STDIO disabled
- mount endpoint under `/_mcp`

## Keep the tool surface fixed
Do not create one MCP tool per library.
Use a small stable `librarian-*` tool set.

### Tools

#### `librarian-search`
Purpose:
- find relevant libraries

Inputs:
- `query` (required string)
- `topn` (optional int; default `10`, min `1`, max `50`)

Behavior:
- return only libraries in `ready` state
- use hybrid ranking:
  - DB partial matching (`LIKE`) over library metadata
  - Vera semantic search over a shared metadata corpus (description docs)
- union both result sets and rank deterministically
- output fields per result:
  - `slug`
  - `description`
  - `gitUrl`
  - `lastIndexedAt`
  - `matchReason`

Metadata corpus:
- shared corpus directory (single place)
- incrementally updated on library create/update/delete
- include description docs with enough metadata for search relevance (`slug`, `gitUrl`, description text)

#### `librarian-query`
Purpose:
- run a Vera semantic query within one specific library

Inputs:
- `library` (required slug)
- `query` (required string)
- optional filters:
  - `lang`
  - `path`
  - `type`
  - `scope`
  - `limit` (default `20`, min `1`, max `100`)

Behavior:
- resolve library by slug
- ensure library is `ready`
- call local `VeraCli` for that library only
- return TOON text response

#### `librarian-read`
Purpose:
- read text files from one specific library with line windows (Pi read-style)

Inputs:
- `library` (required slug)
- `file` (required relative path)
- `offset` (optional int, 1-indexed start line; min `1`)
- `limit` (optional int; default `200`, max `2000`)

Behavior:
- resolve library by slug
- ensure library is `ready`
- read only existing text files
- return line-based text output in TOON content

Security constraints:
- access is allowed only if **both** checks pass:
  1. file exists in `Library.readableFiles` JSON map (`path => true`)
  2. realpath stays inside library repo root
- `readableFiles` is produced during sync/index pipeline in `SyncLibraryMessageHandler`
- `readableFiles` stores only text files (no binary files)
- missing/not-allowed/binary path returns a generic not-readable/not-file message

#### `librarian-grep`
Purpose:
- run Vera regex search within one specific library

Inputs:
- `library` (required slug)
- `pattern` (required regex string)
- optional:
  - `path`
  - `lang`
  - `limit` (default `20`, min `1`, max `100`)

Behavior:
- resolve library by slug
- ensure library is `ready`
- run Vera grep/regex search in that library only
- return TOON text response

## Authentication model
MCP auth is independent from browser session login.

### Per-user token auth
- single active MCP token per user
- token format prefixed with `mcp_`
- store only hash (`sha256`) in DB (never plaintext)
- plaintext token is shown only once after regeneration
- accepted headers:
  - `Authorization: Bearer <token>`
  - `X-MCP-Token: <token>`

Authorization rules:
- token must match a user token hash
- user must have `ROLE_MCP`

Implementation:
- dedicated firewall + custom authenticator scoped to `/_mcp`
- do not require browser session login for MCP clients

## User model and admin UI changes
User fields:
- `mcpTokenHash` (unique, nullable)
- `mcpTokenCreatedAt` (nullable)
- `mcpTokenLastUsedAt` (nullable)

Roles:
- use explicit `ROLE_MCP`
- backfill existing admins with `ROLE_MCP` in migration
**IMPORTANT** migrations must be created only by Symfony commands and never by hand.

EasyAdmin UX:
- no token column in list page
- on edit page:
  - disabled masked token field (e.g. `*****`)
  - dedicated “Regenerate MCP token” action
- regenerate flow:
  - generate new token
  - store only new hash
  - redirect back to edit page
  - show temporary readonly plaintext token for copy (session/flash)
  - next open shows masked value only

## MCP response and error format
Return TOON text responses for all tools.
Do not use structured output payloads.

For tool failures:
- return `CallToolResult` with `isError=true`
- content contains TOON with:
  - `message` (human-readable)
  - `retryable` (bool)
  - `hint` (how to fix call)

## Logging
Add enough structured context to diagnose:

- incoming MCP tool name
- selected library slug
- Vera command latency
- failures and retryability

## Important outcome
With this design, new libraries do not require MCP server reload.
Only database rows and search corpus files change.
MCP capabilities remain stable.

## Acceptance criteria

- MCP endpoint is reachable over HTTP at `/_mcp`
- per-user token auth works with both header formats
- `ROLE_MCP` is required
- client can search libraries with `librarian-search`
- client can query a specific indexed library with `librarian-query`
- client can read safe file windows with `librarian-read`
- client can run regex search with `librarian-grep`
- `librarian-read` is sandboxed by manifest + realpath checks
- all tool responses are TOON text
- all tool failures return `isError=true` and TOON `{message,retryable,hint}`
- tool set stays stable as libraries are added or removed
