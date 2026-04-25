# Remove Mailer from this Symfony template

Welcome AI agent!

Your goal is to remove Symfony Mailer completely from this template while keeping the app bootable and Docker setup clean.

## Constraints

- This project uses Docker-first workflows.
- Do not run Composer or PHP on the host.
- Use container commands (for example through `docker compose` / `castor`).
- Never modify skill/instruction files during cleanup. Treat these paths as read-only:
  - `.opencode/skills/`
  - `.agents/`

## 1) Remove mailer dependencies

In `composer.json`, remove mail-related dependencies, including:

- `symfony/mailer`
- `symfony/mime` (if only used by mailer in this template)

Then update the lock file from inside Docker.

## 2) Remove bundle registration/config

If bundle registration exists in `config/bundles.php`, remove mail-related bundle entries.

Delete mailer config files if present:

- `config/packages/mailer.yaml`

Also clean any mailer references in other config files.

## 3) Remove environment variables

Remove mailer env vars from committed env files:

- `.env`
- `.env.dev`
- `.env.prod`
- `.env.prod.local.dist`

Common keys:

- `MAILER_DSN`

## 4) Remove Docker mail service

Remove local mail tooling from compose files:

- `compose.override.yaml` mail service (for example Mailpit)
- any mail-related ports/volumes/env in compose files

Ensure `docker compose config` still validates.

## 5) Remove app-level mail usage

Delete or refactor mail-specific code:

- services dispatching email via `MailerInterface`
- `TemplatedEmail` / `Email` construction code
- notification channels using email only
- tests focused only on mailer behavior

If needed, replace with a no-op placeholder so template behavior remains coherent.

## 6) Clean Castor tasks and docs

Update:

- `castor.php` and `.castor/*.php` (remove mail-specific log/ops tasks)
- `README.md`
- `docs/setup.md`
- `docs/castor.md`

Remove instructions related to local mail UI/tools.

## 7) Verification

Run Docker-based checks and ensure success:

- `docker compose config`
- `docker compose -f compose.yaml -f compose.prod.yaml config`
- app container boot
- tests/smoke checks and static checks if available

## 8) Final quality gate

Before finishing, confirm:

- no `symfony/mailer` in `composer.json`
- no mailer config file in `config/packages/`
- no `MAILER_DSN` in committed env files
- no mail service in compose files
- no mail-specific setup steps in docs
