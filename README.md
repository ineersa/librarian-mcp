# Symfony Web Template

Reusable Symfony 8 template with Docker (FrankenPHP), Castor tasks, Cursor/OpenCode setup, and production-friendly defaults.

## Included in this template

- Docker runtime: FrankenPHP + Mercure + SQLite-ready setup.
- Compose files: `compose.yaml`, `compose.override.yaml`, `compose.prod.yaml`.
- Developer workflow: Castor task runner (`castor.php` + `.castor/`), VS Code wrappers, PHPStan/CS Fixer defaults.
- Agent setup: `.cursor/skills`, `.opencode/skills`, `.opencode/agents`, `AGENTS.md`.
- Environment templates: `.env`, `.env.dev`, `.env.test`, `.env.prod`, `.env.prod.local.dist`.
- Generic docs: local setup, Mercure notes, and deployment guide.

## Install Castor

Install Castor once on your machine:

```bash
curl "https://castor.jolicode.com/install" | bash
```

Then verify:

```bash
castor --version
castor list
```

## Quick start

```bash
castor dev:setup
castor dev:bootstrap
```

`castor dev:bootstrap` does not run database migrations.

Ports are configurable (defaults: app `8080`, HTTPS `8443`, Mailpit UI `8025`).
Set `HTTP_PORT`, `HTTPS_PORT`, `MAILER_SMTP_PORT`, and `MAILER_UI_PORT` in `.env.local` when needed.
If container DNS is broken (for example resolver `127.0.0.53`), set `DOCKER_DNS_PRIMARY` and `DOCKER_DNS_SECONDARY` in `.env.local`.

If your project uses Doctrine migrations:

```bash
castor dev:console "doctrine:migrations:migrate --no-interaction"
```

In another terminal:

```bash
castor dev:messenger-consume
```

Open `http://localhost:${HTTP_PORT:-8080}`.

## Daily run

```bash
castor dev:up
```

Useful lifecycle commands:

- `castor dev:down`
- `castor dev:restart`
- `castor dev:ps`
- `castor dev:logs`

`castor dev:up`, `castor dev:setup`, and `castor dev:bootstrap` automatically stop running containers from other compose projects that already occupy the configured dev ports.

## Production-like local run

```bash
castor prod:up
```

Useful commands:

- `castor prod:down`
- `castor prod:restart`
- `castor prod:ps`
- `castor prod:logs`
- `castor prod:console "doctrine:migrations:migrate --no-interaction"`

## Command reference

- `docs/castor.md`

## Placeholder replacement checklist

This template intentionally uses placeholders in some files.

- `{{PROJECT_PATH}}`: absolute local project path used by `.vscode/*.sh` wrappers.
- `{{APP_DOMAIN}}`: production domain example in `.env.prod.local.dist`.

Suggested replacement command:

```bash
rg -l '\{\{PROJECT_PATH\}\}|\{\{APP_DOMAIN\}\}' . | xargs sed -i "s#{{PROJECT_PATH}}#/absolute/path/to/your/project#g; s#{{APP_DOMAIN}}#app.example.com#g"
```

## Important files

- `AGENTS.md`
- `docs/setup.md`
- `docs/server-deployment.md`
- `docs/mercure.md`
