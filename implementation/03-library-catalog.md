# Stage 3 — Library catalog and CRUD

## Goal
Let an authenticated admin manage the catalog of libraries that this app knows about.

## Architecture

### Service layer
All business logic lives in `LibraryManager` (a Symfony service). Controllers are thin delegates. The entity is a data holder with domain methods that enforce its own invariants.

- **Entity** — database interaction + state invariants (status transitions, property hooks)
- **LibraryManager** — all business logic (name derivation, slug generation, path computation, delete with filesystem cleanup, pre-persist validation)
- **CRUD controller** — thin, delegates to `LibraryManager`
- **VeraCli** — pure CLI wrapper, receives resolved absolute path + `VeraIndexingConfig` VO, no entity awareness

### Validation
Use Symfony validator attributes directly on the entity for simple constraints (required, length, regex). Custom validation (e.g. unique path, GitHub URL parsing) lives in `LibraryManager` as pre-persist checks. No separate DTO — entity receives form data directly for MVP simplicity.

---

## `Library` entity

| Field | Type | Notes |
|---|---|---|
| `id` | int, auto-increment | Primary key |
| `name` | string (255) | Derived from gitUrl + branch (e.g. `symfony/symfony-docs (main)`). User can override. |
| `slug` | string (255), unique index | Mutable MCP identifier. Defaults to slugified name. Used in MCP queries. |
| `gitUrl` | string (255) | Required. GitHub HTTPS URL only for MVP. |
| `branch` | string (255) | Required. Default `main`. |
| `path` | string (255), unique index | `owner/repo/branch` (e.g. `symfony/symfony-docs/main`). Computed from gitUrl + branch. Immutable after creation. |
| `description` | text | Required. 2–3 sentence summary. |
| `status` | backed enum | `LibraryStatus: draft, queued, indexing, ready, failed`. Default `draft`. |
| `veraConfig` | JSON → `VeraIndexingConfig` VO | MVP-safe Vera indexing settings (see below). |
| `lastError` | text, nullable | Truncated to ~2000 chars on persist. |
| `lastSyncedAt` | datetime, nullable | When the repo was last cloned/pulled. |
| `lastIndexedAt` | datetime, nullable | When `vera index` last completed. |
| `createdAt` | datetime | Via `TimestampableEntity` trait. |
| `updatedAt` | datetime | Via `TimestampableEntity` trait. |

### Entity conventions

- Use **asymmetric visibility** (`public private(set)`) for all properties. No getters/setters — access as `$library->name`, `$library->status`, etc.
- Read-write fields: `public` (e.g. `name`, `gitUrl`, `branch`, `description`).
- Read-only externally (set only via domain methods): `public private(set)` (e.g. `id`, `slug`, `path`, `status`, `lastError`, `lastSyncedAt`, `lastIndexedAt`).
- Use `TimestampableEntity` trait for `createdAt` / `updatedAt`.
- Domain methods enforce status transitions and mutate state:

```php
public function syncStarted(): void      // queued|failed → indexing
public function syncFailed(string $error): void  // indexing → failed, sets lastError
public function syncSucceeded(): void    // indexing → ready, sets lastIndexedAt
public function markQueued(): void       // draft|failed → queued
```

Each method throws `LogicException` if the current status doesn't allow the transition.

### Property hooks for `veraConfig`

Doctrine hydrates a private backing field. External code uses the public hook:

```php
#[ORM\Column(type: 'json', name: 'vera_config')]
private ?array $veraConfigData = null;

public ?VeraIndexingConfig $veraConfig {
    get => null !== $this->veraConfigData ? VeraIndexingConfig::fromArray($this->veraConfigData) : null;
    set(VeraIndexingConfig|null $value) => $this->veraConfigData = $value?->toArray();
}
```

### Validation attributes (on entity)

```php
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\NotBlank]
#[Assert\Regex(
    pattern: '~^https://github\.com/[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+(\.git)?$~',
    message: 'Must be a valid GitHub HTTPS URL.'
)]
public string $gitUrl = '';

#[Assert\NotBlank]
#[Assert\Length(max: 255)]
public string $branch = 'main';

#[Assert\NotBlank]
#[Assert\Length(max: 255)]
public string $slug = '';

#[Assert\NotBlank]
public string $description = '';
```

### Name derivation (in LibraryManager)

- Parse `owner/repo` from `gitUrl` (e.g. `https://github.com/symfony/symfony-docs` → `symfony/symfony-docs`).
- Append branch if not `main` (e.g. `symfony/symfony-docs (6.4)`).
- User can override the auto-derived name.

### Slug behavior (in LibraryManager)

- Default: slugify the `name` (e.g. `symfony/symfony-docs` → `symfony-symfony-docs`).
- User can edit freely (package names sometimes differ from repo names).
- Must be unique — enforced by unique index + pre-persist check.
- Used as the MCP library identifier. Not used for filesystem paths.

### Path computation (in LibraryManager)

- `owner/repo/branch` extracted from `gitUrl` + `branch` (e.g. `symfony/symfony-docs/main`).
- Stored relative in the `path` column.
- Immutable after creation.
- LibraryManager prepends `$projectDir/data/libraries/` at runtime to build the absolute path passed to VeraCli.
- Pre-persist uniqueness check: if a library with that path already exists, return a clear error ("Repository X with branch Y already exists as library 'Z'").

---

## `VeraIndexingConfig` value object

```php
readonly class VeraIndexingConfig
{
    public function __construct(
        /** @var array<string> One glob pattern per entry */
        public array $excludePatterns = [],
        public bool $noIgnore = false,
        public bool $noDefaultExcludes = false,
    ) {}

    public static function fromArray(array $data): self
    public function toArray(): array
}
```

---

## Vera settings in MVP

| Field | Form control | Maps to `vera index` flag |
|---|---|---|
| `excludePatterns` | multi-line textarea (one glob per line) | `--exclude <pattern>` (repeatable) |
| `noIgnore` | checkbox | `--no-ignore` |
| `noDefaultExcludes` | checkbox | `--no-default-excludes` |

Default exclude patterns hint (shown as placeholder in the textarea):

```
_build/**
_images/**
**/*.rst.inc
.alexrc
.doctor-rst.yaml
```

Do **not** expose every Vera config key yet. That belongs to a later stage.

---

## `LibraryStatus` enum

```php
enum LibraryStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Indexing = 'indexing';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

Allowed transitions:

```
draft  → queued
queued → indexing
failed → queued          // retry
indexing → ready | failed
```

Any other transition throws `LogicException`.

---

## `SyncLibraryMessage`

Create the message class in this stage. Dispatch from the `Sync now` EasyAdmin action. Sets status to `queued` via `LibraryManager::markQueued()`.

The message handler is **Stage 4's concern** — do not create a handler or stub in this stage.

```php
class SyncLibraryMessage
{
    public function __construct(
        public readonly int $libraryId,
    ) {}
}
```

---

## VeraCli changes

Remove `getRepoPath()` and the slug-based path logic. VeraCli becomes a pure CLI wrapper:

```php
public function cloneRepository(string $absolutePath, string $gitUrl, string $branch): string
public function indexLibrary(string $absolutePath, VeraIndexingConfig $config): string
```

LibraryManager resolves the absolute path and passes it in. VeraCli does not know about entities or path conventions.

---

## CRUD UI

Use EasyAdmin CRUD. One `LibraryCrudController` with:

- **Index** — list with partial search by name, status badge, last indexed timestamp. Actions: detail, edit, delete, sync now.
- **Detail** — all fields + read-only preview of normalized `veraConfig`.
- **New/Edit** — form fields. `veraConfig` rendered as virtual fields (textarea for exclude patterns, checkboxes for booleans). Assembled into `VeraIndexingConfig` VO in `persistEntity()` / `updateEntity()`.
- **Sync now** — custom action, dispatches `SyncLibraryMessage`, delegates status transition to `LibraryManager::markQueued()`.
- **Delete** — delegates to `LibraryManager::delete()` which removes filesystem directory + DB record. EasyAdmin confirmation dialog.

### Virtual veraConfig fields in configureFields()

Map as unmapped EasyAdmin fields (not bound to entity properties). In `persistEntity()` / `updateEntity()`, read the submitted values from the form, assemble `VeraIndexingConfig`, set it on the entity via `$library->veraConfig = ...`.

---

## LibraryManager service

Responsible for:

- **Name derivation** — `deriveName(string $gitUrl, string $branch): string`
- **Slug generation** — `generateSlug(string $name): string`
- **Path computation** — `computePath(string $gitUrl, string $branch): string`
- **Pre-persist validation** — unique path check, GitHub URL parsing
- **Persist/update** — called from CRUD controller, handles all entity setup
- **Delete** — removes `data/libraries/<path>` directory + entity
- **Mark queued** — `markQueued(Library $library): void` + dispatch `SyncLibraryMessage`
- **Status transitions** — delegated to entity domain methods

---

## Acceptance criteria

- Admin can create, edit, view, and delete library records from EasyAdmin
- Library records persist all MVP metadata
- Partial search by library name works
- Slug is unique and editable
- Path is unique and immutable after creation
- Status transitions are enforced (invalid transitions throw)
- Delete removes both DB record and filesystem directory
- `Sync now` dispatches `SyncLibraryMessage` and sets status to `queued`
- VeraConfig stored as JSON, rendered as form fields, assembled into VO on save

## Dependency on next stage

This stage stores library metadata and dispatches sync messages.
Stage 4 adds `SyncLibraryMessageHandler` — the actual clone/index pipeline.
