# Remove Security from this Symfony template

Welcome AI agent!

Your goal is to remove Symfony Security completely from this template when authentication is not needed (no login form, no create-user command), while keeping the app bootable.

## Constraints

- This project uses Docker-first workflows.
- Do not run Composer or PHP on the host.
- Use container commands (for example through `docker compose` / `castor`).
- Never modify skill/instruction files during cleanup. Treat these paths as read-only:
  - `.opencode/skills/`
  - `.agents/`

## 1) Remove security dependencies

In `composer.json`, remove security-related dependencies, including:

- `symfony/security-bundle`
- any authenticator/password/ACL packages if present

If maker tooling is only used for security scaffolding in this template, consider removing:

- `symfony/maker-bundle` (optional; only if no longer desired)

Then update the lock file from inside Docker.

## 2) Remove security bundle registration

Update `config/bundles.php` and remove:

- `Symfony\\Bundle\\SecurityBundle\\SecurityBundle`

Also remove any dev/test-only security bundles if present.

## 3) Remove security config files

Delete security config files if present:

- `config/packages/security.yaml`
- `config/packages/test/security.yaml`

Remove related references from other config files (for example access control, user providers, password hashers).

## 4) Remove login and user-management artifacts

Delete or refactor files related to login form and user creation command/scaffolding, such as:

- login/logout controllers
- security authenticators
- user entity implementing Symfony user interfaces
- user repository/provider used only for auth
- CLI command used to create users
- templates for login/reset-password/register
- tests that target authentication flows

If a user model is still needed for business data, keep it as a plain domain model without Symfony Security interfaces.

## 5) Remove security usage in code and templates

Remove calls/usages such as:

- `is_granted(...)`
- `app.user`
- `app_login`, `app_logout`, `security.*` route names
- `#[IsGranted]` attributes
- `denyAccessUnlessGranted(...)`

Replace with neutral behavior suitable for a public, auth-free template.

## 6) Clean routes, docs, and Castor tasks

- Remove security/login references from routing and controllers.
- Remove docs sections related to login/auth user bootstrap from:
  - `README.md`
  - `docs/setup.md`
  - `docs/castor.md`
  - other docs if present
- Remove Castor tasks that are purely auth-user helpers (if any).
- Remove auth-related command references from `docs/castor.md` if any

## 7) Environment cleanup

Remove security-only env vars if present (for example password hasher tuning or auth-specific secrets), while keeping `APP_SECRET` required by Symfony.

## 8) Verification

Run Docker-based checks and ensure success:

- `docker compose config`
- `docker compose -f compose.yaml -f compose.prod.yaml config`
- app container boot
- route listing / smoke checks
- tests and static checks if available

## 9) Final quality gate

Before finishing, confirm no security scaffolding remains in tracked source/config/docs:

- no `symfony/security-bundle` in `composer.json`
- no security bundle in `config/bundles.php`
- no `config/packages/security.yaml`
- no login/user-creation commands or auth-only controllers/templates
- no docs instructing login form setup or create-user command usage
