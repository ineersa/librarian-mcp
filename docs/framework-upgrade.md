# Framework upgrade (plan 01)

## Release choice and dependency constraints

On 2026-11-12 the owner explicitly approved **Symfony 8.2.0-BETA1** because stable 8.2 was not yet available. This is a deliberate prerelease deployment choice, not a claim that Symfony 8.2 is stable.

| Dependency | Locked selection | Reason |
|---|---|---|
| Symfony framework and core components | 8.2.0-BETA1 | Approved target; package-scoped `~8.2.0-BETA1` allowances, global `minimum-stability: stable` |
| PHP / FrankenPHP | PHP 8.5 / FrankenPHP 1.13.5 | Project runtime; upstream image pinned by digest |
| Symfony MCP bundle / MCP SDK | 0.15.0 / 0.9.0 | Bundle 0.15 requires SDK `^0.9`; SDK 0.10 is incompatible |
| Official Mate + Monolog/Symfony extensions | 0.15.0 | Selected stable diagnostic packages; no speculative AI backends |
| EasyAdmin | 5.10.0 | Current compatible stable selection |
| Doctrine ORM | 3.7.6 | Current compatible stable selection |
| Stimulus / Turbo | 3.7.0 | UX 2.x conflicts with Symfony 8.2 AssetMapper |
| Castor / Symfony TUI | 1.9.1 / 8.1.11 | Tooling-only TUI exception: Castor's terminal implementation lacks the new 8.2 interface methods |

Core components that were formerly implicit transitive dependencies have explicit package-scoped constraints because global stable resolution otherwise rejects their prereleases. The tilde ranges remain within Symfony 8.2; `composer.lock` records the exact approved BETA1 selections. They avoid Composer's warnings about exact production constraints without disabling strict validation. Future updates must be reviewed rather than assumed equivalent to this approved release.

`extra.symfony.require` remains scoped to 8.2. The global stability policy is not relaxed. Symfony TUI is an independent tooling component, not a replacement framework minor.

## Integration changes

- MCP 0.15 uses `mcp.servers.librarian`, HTTP at `/_mcp`, an explicit `App\\Mcp\\Tool\\` registry, native filesystem sessions, and the existing token authenticator. `MCP_ALLOWED_HOSTS` is a CSV allowlist; production must include the external hostname without scheme or port.
- The old TraceableRegistry reset compiler pass is removed: that internal bundle service no longer exists in 0.15. No replacement reset infrastructure was added.
- The newer Mercure runtime explicitly uses `protocol_version_compatibility 8` to preserve the existing publisher/subscriber JWT and topic contract. This is a runtime compatibility setting, not a topic/auth redesign.
- Vera still builds from the existing fork/branch and uses the existing application wrappers and model configuration. Its upstream `scripts/bootstrap-vendored-grammars.sh` is required before compiling current sources. The build cache is scoped to Librarian so earlier incomplete grammar artifacts are not reused.
- Castor is the host entry point for containerized Composer, console, static QA, Playwright, and bounded official Mate diagnostics. Finance-specific proxy, provider, tenant, cassette, and agent-index infrastructure was not imported.
- Development/browser networks, image names, ports, and runtime labels are checkout-scoped. Published development/browser URLs bind to loopback. Production retains its existing compose project name, port configuration, and data mounts.
- Browser SQLite, libraries, MCP sessions, Mercure state, cache, logs, and shared runtime files live under `var/browser-live/`. The synthetic admin preparation helper verifies the exact isolated database path and refuses ambient database URLs. Owner/production databases are not test fixtures.
- DAMA, Messenger-test, FakeVera/MockHub, the old unit/kernel tests, obsolete AI-index/dead-code integrations, and generated custom Mate wrappers are removed. Strict PHPStan rules remain enabled without a baseline. Deprecated configuration keys with invariant defaults are removed rather than silenced.

## Verification and limits

Validated on 2026-11-12 through the host Castor entry point:

| Check | Observed result |
|---|---|
| `tooling:prepare` | Tooling image, locked dependency install, and Chromium preparation passed |
| `cs:fix` | Passed |
| `check` | Combined static + real-network headless browser gate passed |
| `qa:static` (included in `check`) | Strict Composer validation, style check, PHPStan level 6 with strict rules, and runtime-complete Symfony LSP passed; zero LSP diagnostics |
| `test` (included in `check`) | Five journeys, 34 assertions; PHP 8.5.12 / PHPUnit 13.4.6 |
| `test:database:fresh` | Existing three migrations applied and Doctrine mapping/database schema validated on a newly allocated isolated diagnostic SQLite file |
| `prod:build` | Production image built, including no-dev Composer install, production cache warmup, Tailwind, and production-only AssetMapper compilation |
| `mate:discover`, `mate:tools`, `mate:inspect symfony-services` | Official extensions and tool schemas available; curated project guidance preserved |
| `mate:call symfony-services` (query `messenger.transport.async`, limit 5) | Async transport present in dev/test container metadata |

The first successful combined gate took approximately 14.6 seconds, including a 4.5-second browser suite. Reports are private under `var/qa/` and overwritten by subsequent runs. The isolated web and Messenger worker containers reached healthy state; authenticated MCP initialize/initialized/tools-list completed against the real endpoint. Production containers were **not** started or migrated; only their image was built.

The saved suite deliberately covers five happy-path journeys: homepage, login/logout, user CRUD, library list/registration form, and MCP token generation plus initialize/initialized/tools-list over real HTTP. It does **not** establish full parity with removed kernel/unit tests. See [TESTING.md](../TESTING.md) for explicit gaps.

No production cutover, owner-data reset, provider/model calls, repository ingestion, backup system, or domain rewrite is part of this change. A healthy worker and a successful MCP handshake do not prove real Vera indexing, embedding/reranking availability, tool invocation against populated libraries, or Mercure browser delivery.

Exploratory happy-path inspection found a Users **Actions** dropdown click intercepted by EasyAdmin's `ea-layout-toggle-content-rail`; keyboard activation worked. It was not compared with the pre-upgrade baseline, and no layout-permutation investigation or styling workaround is claimed here. The saved CRUD journey follows its actual edit/detail links and does not establish that dropdown pointer behavior.

The external `playwright-cli` skill's fallback (`npx playwright-cli`, line 17) failed because the official package is `@playwright/cli`. Exploration used version 0.1.24 from an isolated temporary installation. The external skill was not edited.

## Documentation consulted

The Symfony search and relevant documentation were read before implementation and checked against the installed package sources:

- [Symfony 8.2.0-BETA1 release](https://symfony.com/blog/symfony-8-2-0-beta1-released)
- [Symfony MCP bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html)
- [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html)
- [Framework configuration](https://symfony.com/doc/current/reference/configuration/framework.html)
- [Doctrine integration](https://symfony.com/doc/current/doctrine.html)

Installed source, not current-documentation assumptions, determines the prerelease APIs. In particular the MCP registry/configuration, Symfony 8.2 deprecated defaults, DoctrineBundle's fixed `auto_mapping: false` behavior, and Castor/TUI interface compatibility were inspected directly.
