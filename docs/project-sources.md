# Local projects and Hatfield source discovery (plan 03)

## Current product boundary

Librarian registers **local directories**, not GitHub libraries. It discovers Hatfield OM/session metadata without booting Hatfield, loading extension PHP, running source migrations, cloning repositories, invoking Vera, or calling model providers. The former library CRUD, clone/delete pipeline, Vera binary/config, metadata corpus, and four library MCP tools are retired.

**Sources discovered** means discovery succeeded, not that memories have been indexed. Semantic indexing/checkpoints and periodic scheduling belong to plan 04; retrieval tools belong to plan 05. No permanent polling/scheduling interval is selected here. The two-second page refresh only updates a pending scan's admin detail view.

## Identity and removal

- `Project.id` is an auto-increment integer, stable across scans, renaming, and explicit administrator path rebinding.
- Trimmed, case-insensitive display names must be unique. Canonical directories and normalized Git origins must also be unique. A second checkout with the same origin is rejected, not silently merged. Non-Git/no-origin directories are supported.
- Editing the directory rebinds the **same registration ID**. Nothing infers identity from a basename, session number, name, or origin.
- Deletion removes only the Librarian registration. It never removes the project directory, `.hatfield`, OM database, journals, or any external/symlink destination.
- Legacy `libraries` rows and old migration history are preserved in place. The retired table is excluded from ORM schema ownership; the generated migration adds `projects` and its unique indexes only. Existing clone/index directories are not automatically cleaned up.

## Source settings contract

Discovery resolves the relevant settings using Hatfield's YAML overlay semantics:

1. Source defaults: `.hatfield/extensions-data/observational-memory/om.sqlite` and `.hatfield/sessions` under the selected project.
2. Explicit Hatfield user-home `.hatfield/settings.yaml`.
3. Project `.hatfield/settings.yaml`.

Associative maps merge recursively; lists, scalars, and null replace the preceding value. A missing optional settings file is valid, unlike malformed YAML. The OM extension must be in `extensions.enabled` as `Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension`. Discovery does not invent an `observational_memory.enabled` flag.

- OM override: `observational_memory.storage.database`. Absolute paths are retained; relative paths are joined to the selected project, matching OM's path rules (not the session-path placeholder rules).
- Session override: `sessions.path`. Hatfield session resolution expands `~` and `%kernel.project_dir%`; relative session paths use the selected project. The kernel placeholder refers to the **Hatfield application**, not Librarian. It does not invent `%user.home%` or `%project.root%` expansion.
- `HATFIELD_USER_HOME` is the explicit logical Hatfield user home. It is required even when no user settings file exists; its mapped directory must be available. Never implicitly use the container's `/root`.
- `HATFIELD_APP_ROOT` is required when session configuration uses `%kernel.project_dir%`.
- `HATFIELD_PATH_MAPPINGS` is a JSON object of absolute logical-host prefix to service-visible prefix. Longest matching path-segment prefix wins. It maps global settings and configured source paths; a mapping does not mount anything by itself.

Existing source paths are canonicalized, including symlinks, and their actual destinations are reported. Per the owner's local single-admin utility decision, **there is no security root allowlist or external-path approval workflow**. `PROJECT_BROWSE_ROOT` is only the directory picker's starting point. Administrators may choose any readable, deliberately mounted path. Do not expose this relaxed utility as though it had a filesystem sandbox.

## Supported OM schema and metadata

Supported source version rows are the complete known set:

- `20260722_001_domain`
- `20260725_002_reflection_multi_and_indexes`
- `20260726_003_active_generation_and_relevance_text`
- `20260729_004_current_activity`
- `20260922_005_semantic_health`
- `20261021_006_semantic_source_revisions`

Missing/unknown versions or missing required tables/columns produce an explicit discovery error; Librarian never upgrades a source. It reads retained observation/reflection row counts, canonical per-session `events.jsonl` availability/count, the source revision, and source-change journal row count. **Source change entries are not an index backlog or a Librarian checkpoint.** They are not consumed or acknowledged.

OM is opened with SQLite's read-only URI mode (not `immutable`) and inspected in one short read transaction so WAL-backed source state remains eligible for SQLite's normal consistent read facilities. No raw live database copying, source checkpointing, journal updates, or long Librarian write transaction is used. Docker source binds should also be read-only. A source's required live WAL/SHM files and filesystem permissions must be readable; an unavailable live source is an error, not grounds to copy or repair it.

The admin detail view reports paths, schema, counts, revision, scan timestamps, not-indexed state, and actionable errors. Distinct failures include unavailable context/root/mount, missing `.hatfield`, disabled OM, missing/unreadable database, unsupported schema, malformed settings, and unreadable session journals. No fake empty OM database is created.

## Docker source mounts

`PROJECTS_HOST_PATH` defaults to this checkout (`.`) and is bound read-only at `/projects` in dev and production. Change it deliberately to a host directory containing your projects. Both web and Messenger consumer need the **same** source mounts and environment.

For additional settings/custom-data mounts, create the ignored `compose.sources.yaml`. Castor appends this standard Compose override for dev/production, **never** for the isolated browser stack. Example for development (replace placeholder host paths; bind sources must already exist):

```yaml
x-source-environment: &source-environment
  PROJECT_BROWSE_ROOT: /projects
  HATFIELD_USER_HOME: /home/operator
  HATFIELD_APP_ROOT: ''
  HATFIELD_PATH_MAPPINGS: '{"/home/operator":"/hatfield-home"}'

x-source-mounts: &source-mounts
  - type: bind
    source: /actual/host/projects
    target: /projects
    read_only: true
    bind:
      create_host_path: false
  - type: bind
    source: /actual/host/home/.hatfield
    target: /hatfield-home/.hatfield
    read_only: true
    bind:
      create_host_path: false

services:
  php:
    environment: *source-environment
    volumes: *source-mounts
  worker:
    environment: *source-environment
    volumes: *source-mounts
```

For a production checkout, use the production consumer service name **`messenger` instead of `worker`** in that override. Do not use a development-only service declaration unchanged in production. Set an application-root mapping and matching read-only bind when Hatfield session settings require it. Add explicit read-only mounts for custom data outside the shown trees. Do not mount unrelated credentials or copy whole runtime/queue/lock/PID trees into Librarian storage to resolve source paths.

Use `vendor/bin/castor dev:stack:up` and the printed URL. Register a directory with **Choose directory**, inspect the scan result, and use **Scan sources** to rescan. Production lifecycle and migration authorization remain in [server-deployment.md](server-deployment.md); this change does not itself authorize a production cutover.

EasyAdmin pages use full-page navigation (`data-turbo="false"` and UX Turbo's `turbo_page_requires_reload()` helper) so its native forms and confirmation dialogs initialize on every visit. The directory picker remains a Stimulus controller; Turbo stays installed for other application surfaces.

Admin pages load the separate `admin` AssetMapper entry, which starts Stimulus without importing the public site's Tailwind reset. The picker uses EasyAdmin's bundled Bootstrap utilities, and login uses EasyAdmin's built-in template. Keep public `app.css` out of admin pages: its reset layer overrides EasyAdmin's headings, spacing, and buttons.

## Validation evidence

Saved acceptance uses only synthetic source trees built under ignored `var/browser-live/project-sources`, mounted at `/project-sources` read-only for web/worker. No private Hatfield state is committed. Fixtures exercise default/custom relative/absolute paths, overlays, source errors, external/symlink paths under the relaxed policy, uniqueness, same-numbered sessions in separate projects, and unchanged source hashes after failed scans/removal. Each journey deletes its own registrations through the UI; databases are not blanket-purged.

Commands and actual results are recorded with the implementation/PR, not inferred from this document. Run `vendor/bin/castor cs:fix`, the relevant `test` journeys, and `qa:static`; `check` is the complete gate. Read the generated migration before applying it, even to isolated task-owned storage.
