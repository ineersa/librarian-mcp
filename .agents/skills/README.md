# Project skills

## Symfony UX skills

The Stimulus, Turbo, and Symfony UX skill directories are taken from [smnandre/symfony-ux-skills](https://github.com/smnandre/symfony-ux-skills) by Simon Andre (via the personal-finance-agent snapshot).

- Upstream commit: `1e99301a6255724eca9c49ce9cdb8c241771ab05`.
- License: MIT, preserved in [LICENSE.symfony-ux-skills](LICENSE.symfony-ux-skills).
- Installed here: `stimulus`, `turbo`, `symfony-ux` only — matching packages actually used by Librarian (`symfony/stimulus-bundle`, `symfony/ux-turbo`).
- Not installed: `twig-component`, `live-component`, `ux-icons`, `ux-map` (corresponding Composer packages are not project dependencies). Do not invent those APIs in application code.

Reproduce the installed skill inventory with:

```sh
find .agents/skills -mindepth 2 -maxdepth 2 -name SKILL.md | sort
```

Installing a skill does not select or install its corresponding application package. Upstream skill text may still mention Symfony UX 2.22–2.28+ / Symfony 8.0; this application targets Symfony 8.2 with Stimulus/Turbo **3.7**. Verify examples against installed versions before copying APIs.

## Project-specific skills

| Skill | Purpose |
|---|---|
| `castor` | Host `vendor/bin/castor` task discovery and operations |
| `mate-tools` | Bounded Mate diagnostics through Castor |

## Updating UX skills

1. Inspect the intended upstream revision and its changes.
2. Copy complete skill directories for packages that remain installed, including references.
3. Preserve the upstream license and update the commit note above.
4. Keep project-specific rules in [AGENTS.md](../../AGENTS.md) rather than silently modifying upstream skill text for domain reasons.

Report incorrect or version-incompatible skill instructions before changing them.
