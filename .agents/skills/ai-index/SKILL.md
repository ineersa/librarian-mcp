---
name: ai-index
description: Defines AI index navigation and regeneration workflow for this repository.
license: MIT
metadata:
  author: ai-index
  version: "0.1"
---

# AI Documentation Index

## Quick start

```bash
castor dev:ai-index "generate --changed"
```

## Core rules

- Source of truth is **PHP source code**.
- Generated AI index files should not be edited manually.
- Namespace-level `ai-index.toon` files may keep curated description fields.

## Maintenance commands

- `castor dev:ai-index "setup"` — install/update agent templates and AGENTS.md section.
- `castor dev:ai-index "wiring:export"` — export Symfony DI wiring map.
- `castor dev:ai-index "generate --changed"` — regenerate only changed class/namespace AI indexes.
- `castor dev:ai-index "generate --all --force"` — full refresh of class/namespace AI indexes.

## Suggested workflow

1. Run `castor dev:ai-index "wiring:export"`.
2. Run `castor dev:ai-index "generate --changed"` for incremental updates.
3. Run `castor dev:ai-index "generate --all --force"` for full refresh.
