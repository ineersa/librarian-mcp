# Framework compatibility

## Approved release and constraints

On 2026-11-12 the owner explicitly approved **Symfony 8.2.0-BETA1** because stable 8.2 was not yet available. Use package-scoped stability allowances; global `minimum-stability` remains `stable`.

| Dependency | Locked selection | Compatibility constraint |
|---|---|---|
| Symfony framework and core components | 8.2.0-BETA1 | Package-scoped `~8.2.0-BETA1` ranges stay within Symfony 8.2 |
| PHP / FrankenPHP | PHP 8.5 / FrankenPHP 1.13.5 | Runtime image pinned by digest |
| Symfony MCP bundle / MCP SDK | 0.15.0 / 0.9.0 | Bundle 0.15 requires SDK `^0.9`; SDK 0.10 is incompatible |
| Official Mate + Monolog/Symfony extensions | 0.15.0 | Selected stable diagnostic packages |
| EasyAdmin | 5.10.0 | Admin UI |
| Doctrine ORM | 3.7.6 | PostgreSQL application metadata |
| Stimulus / Turbo | 3.7.0 | UX 2.x conflicts with Symfony 8.2 AssetMapper |
| Symfony AI Postgres store | 0.15.0 | Native generation-scoped document storage |
| Castor / Symfony TUI | 1.9.1 / 8.1.11 | Castor does not implement the new 8.2 terminal interface |

Explicit core constraints allow required prerelease transitive dependencies without relaxing global stability. `extra.symfony.require` stays scoped to 8.2. The TUI pin is tooling-only, not a framework downgrade. `composer.lock` records the exact approved selections; dependency updates require review.

## Active integrations

- MCP 0.15 uses `mcp.servers.librarian`, an explicit registry, native filesystem sessions, and optional token authentication at `/_mcp`. Public memory tools remain plan 05. Production sets the `MCP_ALLOWED_HOSTS` CSV allowlist to its external hostname without scheme or port.
- Mercure uses `protocol_version_compatibility 8` for the existing JWT/topic contract.
- Doctrine metadata and the official Symfony AI store share PostgreSQL + pgvector. Hatfield SQLite sources are opened read-only.
- Castor is the host entry point for containerized Composer, console, static QA, Playwright, and selected Mate diagnostics.
- Development/browser stacks use the repository-shared Caddy proxy and checkout-private PostgreSQL networks. See [Castor operations](castor.md) for port adoption and explicit recreation.

## Validation

Current checks and limitations are recorded in [indexing validation](indexing-validation.md). [TESTING.md](../TESTING.md) defines the five happy-path browser journeys and isolated database policy. This compatibility table is not evidence that runtime checks passed.

## Upstream documentation

- [Symfony 8.2.0-BETA1 release](https://symfony.com/blog/symfony-8-2-0-beta1-released)
- [Symfony MCP bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html)
- [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html)
- [Framework configuration](https://symfony.com/doc/current/reference/configuration/framework.html)
- [Doctrine integration](https://symfony.com/doc/current/doctrine.html)

Verify prerelease APIs against installed sources rather than assuming current-documentation compatibility.
