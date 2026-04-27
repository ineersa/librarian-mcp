# Stage 2 — Backoffice security with EasyAdmin

## Goal
Set up the admin area fast by combining:

- Symfony Security for authentication
- EasyAdminBundle for the backend UI shell

## Important note
EasyAdmin helps a lot with admin UI and CRUD, but security still comes from Symfony Security.
That matches EasyAdmin's own model: protect `/admin` with normal Symfony security rules.

## Scope

### Authentication model
Use standard Symfony session authentication:

- `User` Doctrine entity
- email + hashed password
- roles array
- login form
- logout route

For MVP, skip self-registration.

Create the first admin via a console command, e.g.:

- `app:user:create-admin`

## Authorization model
Keep it simple:

- all admin pages require `ROLE_ADMIN`
- homepage can stay public
- no per-library permissions in MVP

## EasyAdmin work
Install and configure EasyAdminBundle, then create:

- `DashboardController` mounted under `/admin`
- menu entries for Libraries and logout
- later, CRUD controllers for library management

At this stage, the dashboard can be minimal.
It mainly proves that login + admin shell work.

## Data model

### `User`
Suggested fields:

- `id` (ULID)
- `email`
- `roles`
- `password`
- `createdAt`
- `updatedAt`

## Security config changes

- replace in-memory provider with Doctrine user provider
- add form login firewall
- add logout path
- add access control for `^/admin`
- keep profiler/assets routes public in dev

## Pages in this stage

- `/login`
- `/admin`

No custom profile page is needed for MVP.

## Acceptance criteria

- app has real persisted admin users
- browser login/logout works
- `/admin` is protected by `ROLE_ADMIN`
- EasyAdmin dashboard loads after login
- first admin can be created without touching the database manually

## Notes for later stages

- library CRUD itself is covered in Stage 3
- MCP token auth remains separate and is handled in Stage 5
