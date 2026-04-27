# Stage 7 — Low-priority exploration

These items are useful, but should stay behind MVP and the first lifecycle iteration.

## 1. Visualize Vera index contents

### Goal
Provide read-only visibility into what Vera actually stored.

### Possible implementation
- inspect the `.vera` SQLite database for a selected indexed library/version
- build a small admin page showing:
  - indexed file count
  - chunk count
  - top languages
  - top directories
  - maybe sample chunks or symbols

### Warning
Treat this as diagnostics only.
Do not couple application logic tightly to Vera's internal SQLite schema unless that schema is guaranteed stable.

## 2. Better library discovery than `LIKE`

### MVP today
- SQL `LIKE %name%`

### Future options
- SQLite FTS on `name` + `description`
- trigram-like fuzzy search if database changes later
- Vera-backed semantic search over library metadata only

A good future direction is a separate “catalog search” index built from:

- library name
- description
- tags
- detected overview text

## 3. Intent search across libraries

Longer term, users may want:

- “which library contains docs about X?”
- “search all indexed libraries, then show the best matching libraries first”

That is a different problem from current library-specific querying.
It likely deserves:

- a catalog-level ranking layer
- optional semantic embeddings on library metadata
- then a second hop into the chosen library index

## 4. Better observability
Later it may be worth adding:

- sync history table
- job duration metrics
- MCP usage audit log
- bridge latency dashboard

## Acceptance criteria for taking this stage on
Only start this stage when:

- MVP is already stable
- library lifecycle flows are stable
- real users are asking for discovery/diagnostics improvements
