# Remove Doctrine from this Symfony template

Welcome AI agent!

Your goal is to remove Doctrine completely from this template while keeping the app bootable and tests/tooling healthy.

## Constraints

- This project uses Docker-first workflows.
- Do not run Composer or PHP on the host.
- Use container commands (for example through `docker compose` / `castor`).
- Preserve the `data/` directory and `data/.gitignore`.
- Do not delete `data/` even after Doctrine removal; it is used by runtime storage (for example rate limiter/cache) and Docker volume mounts.
- Do not delete `config/reference` during cleanup; it is optional/generated and not part of Doctrine removal.
- Never modify skill/instruction files during cleanup. Treat these paths as read-only:
  - `.opencode/skills/`
  - `.agents/`

## 1) Remove Doctrine dependencies

In `composer.json`, remove all Doctrine runtime and test dependencies, including:

- `doctrine/doctrine-bundle`
- `doctrine/doctrine-migrations-bundle`
- `doctrine/orm`
- `symfony/doctrine-messenger`
- `dama/doctrine-test-bundle` (from `require-dev`)
- `stof/doctrine-extensions-bundle` if it is only here for Doctrine integration

Then update the lock file from inside Docker.

## 2) Remove Doctrine bundle registration

Update `config/bundles.php` and remove Doctrine-related bundles:

- `Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle`
- `Doctrine\\Bundle\\MigrationsBundle\\DoctrineMigrationsBundle`
- `DAMA\\DoctrineTestBundle\\DAMADoctrineTestBundle`
- `Stof\\DoctrineExtensionsBundle\\StofDoctrineExtensionsBundle` (if removed from Composer)

## 3) Remove Doctrine configuration files

Delete Doctrine config files if present:

- `config/packages/doctrine.yaml`
- `config/packages/doctrine_migrations.yaml`
- any Doctrine-specific config in `config/packages/test/` and `config/packages/dev/`

Also remove Doctrine mapping/migration-specific references from other config files.

## 4) Remove DB and migration references from env/config

- Remove `DATABASE_URL` lines from `.env` and related env files where they are only used for Doctrine.
- Remove/adjust any Castor tasks that call Doctrine commands:
  - `doctrine:migrations:*`
  - `doctrine:*`
  - any migration or schema helper target

## 5) Remove Doctrine-persistent Messenger usage (if present)

If Messenger transport uses Doctrine DSN in `.env` / `config/packages/messenger.yaml`, switch to a non-Doctrine transport suitable for a template (for example `in-memory://` for tests and a documented placeholder transport for dev/prod).

Ensure no config still requires Doctrine DBAL.

## 6) Remove leftover app artifacts tied to Doctrine

Delete or refactor app files that are Doctrine-only, such as:

- entity/repository classes in `src/Entity` and `src/Repository`
- migration files in `migrations/`
- tests that depend on Doctrine persistence

If Doctrine is fully removed from the template, also remove the `src/Entity/` and `src/Repository/` directories when they are empty or only contain Doctrine placeholders.

If a directory must remain in git for template structure, keep only `.gitignore` placeholders.

Never remove `data/`; if Doctrine-related files inside it must be cleaned, keep the directory and `data/.gitignore` intact.

## 7) Clean docs and developer commands

Update docs to remove Doctrine-specific setup/commands:

- `README.md`
- `docs/setup.md`
- `docs/castor.md`
- any deployment or ops docs mentioning migrations/DB setup

Make sure setup instructions still produce a working local app.

## 8) Verification

Run from Docker-based workflow and ensure success:

- Compose config validation
- app container boot
- test suite (or at least smoke checks)
- static checks (`phpstan`, cs fixer checks if configured)

## 9) Final quality gate

Before finishing, confirm no Doctrine references remain in tracked source/config/docs (excluding vendor and historical lock metadata comments):

- no `doctrine/` packages in `composer.json`
- no Doctrine bundles in `config/bundles.php`
- no Doctrine config files in `config/packages/`
- no Doctrine tasks in `.castor/dev.php` and `.castor/prod.php`
- no Doctrine setup docs in `README.md` and `docs/`
