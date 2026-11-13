# Server deployment (FrankenPHP, Docker, TLS, domain)

This guide assumes `compose.yaml` + `compose.prod.yaml`. Prefer Castor production tasks discovered with `vendor/bin/castor list prod` over raw compose when those tasks exist.

## Framework upgrade note

Symfony **8.2.0-BETA1** was owner-approved on 2026-11-12 because stable 8.2 was unavailable. The framework requires PHP 8.4.1+; this project uses PHP 8.5. See [framework upgrade notes](framework-upgrade.md) for dependency pins.

Librarian registers local projects, discovers sources read-only, and builds its hybrid indexes on shared PostgreSQL + pgvector; see [project-sources.md](project-sources.md) and [indexing-design.md](indexing-design.md). Postgres image: `pgvector/pgvector:pg17-bookworm` (digest `sha256:b513c48566e4777cd3370451400000c78eda6a08131d68f4c31d4a76f27bd8fd`). PostgreSQL publishes no host ports.

## Default mode (recommended)

The existing compose default publishes HTTP on the LAN:

- `${APP_HTTP_BIND:-0.0.0.0}:${APP_HTTP_PORT:-8093}:80`

For host nginx TLS, explicitly set `APP_HTTP_BIND=127.0.0.1` and proxy to `APP_HTTP_PORT`. The upgrade does not silently change existing bindings.

## One-time setup

1. Copy prod local env file:

   ```bash
   cp .env.prod.local.dist .env.prod.local
   ```

2. Edit `.env.prod.local` and set real production values:

   - `APP_SECRET`
   - `DEFAULT_URI` (for example `https://librarian.example.com`)
   - `MCP_ALLOWED_HOSTS` (comma-separated hostnames without scheme/port; include `librarian.example.com`)
   - `HATFIELD_USER_HOME` (required)
   - `HATFIELD_APP_ROOT` when settings use `%kernel.project_dir%`
   - `HATFIELD_PATH_MAPPINGS` when host/service path prefixes differ
   - `DATABASE_URL` (shared Doctrine + official Postgres store)
   - `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` (must match `DATABASE_URL`; password is needed for host Compose interpolation; do not commit secrets)
   - `CODERANK_URL`, `CODERANK_MODEL`, `CODERANK_ARTIFACT`
   - `BGE_URL`, `BGE_MODEL`
   - `INDEX_INTERVAL`, `LOCK_DSN`
   - `MERCURE_JWT_SECRET`, `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`
   - `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`
   - `SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port`

   Production compose receives `--env-file .env.prod.local`. Production web and worker must share the same Postgres database and lock store. Keep lock DSN overrides on shared storage, not container-local paths. See `.env.prod.local.dist`.

3. Configure the published app port and host project bind in `.env` (Compose interpolation, not the container-only `.env.prod.local`), or use exported process environment / an explicit bind in `compose.sources.yaml`:

   ```bash
   APP_HTTP_BIND=127.0.0.1
   APP_HTTP_PORT=8081
   PROJECTS_HOST_PATH=/absolute/host/projects
   ```

   Keep it loopback-only (`127.0.0.1`) when nginx is your public entrypoint.

4. Build and start (prefer Castor):

   ```bash
   vendor/bin/castor prod:build
   vendor/bin/castor prod:up
   ```

5. Run migrations:

   ```bash
   vendor/bin/castor prod:console -- doctrine:migrations:migrate --no-interaction
   ```

6. Create an admin user, then configure nginx to proxy the explicit loopback port selected above.

Optional: maintain an ignored `compose.sources.yaml` for additional read-only mounts of user/global/custom Hatfield settings or data. Castor layers it for **dev/prod only**, never for the isolated browser stack. In production use service name `messenger`, not development's `worker`; see [source mounts](project-sources.md#docker-source-mounts).

Use the generated PostgreSQL baseline in `migrations/postgres`. The actual local checkout replacement is recorded in [indexing validation](indexing-validation.md#actual-checkout-replacement).

## Nginx example (host machine)

Replace `librarian.example.com` with your real domain and keep proxy port in sync with `APP_HTTP_PORT`.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name librarian.example.com;

    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name librarian.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080; # Use your APP_HTTP_PORT value
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

## TLS (Certbot) checklist

1. Verify DNS for your real domain points to this server.
2. Ensure inbound port 80 is open (firewall and provider security group).
3. Request cert for the real domain (not a placeholder):

   ```bash
   certbot --nginx -d librarian.example.com
   ```

4. If IPv6 is not configured on this host, remove broken `AAAA` records before retrying.
5. Reload nginx and verify HTTPS.

## Dedicated host mode (Caddy public 80/443)

If no other service owns host 80/443, set in `.env`:

```bash
APP_HTTP_BIND=0.0.0.0
APP_HTTP_PORT=80
```

Then publish host 443 with a local compose override.

## Runtime notes

- `compose.prod.yaml` loads `.env.prod.local` with `env_file` and Castor passes `--env-file .env.prod.local`.
- Caddy reads Mercure keys via `{env.MERCURE_*}`.
- Symfony environment is baked by `composer dump-env prod` during image build.
- Castor loads `.env` and `.env.prod` for compose interpolation, preserving `COMPOSE_PROJECT_NAME=librarian-mcp-prod`. It does not assign a new hashed production project name.
- Project sources are mounted read-only.
- Postgres stays on the private network with no host ports. Worker consumes Messenger `async` and `scheduler_default`.
- Scheduler uses `CallbackMessageProvider` to yield indexing jobs directly (`INDEX_INTERVAL`, default 60s).
- Registration deletion removes owned derived indexes only; never source trees.
- Do not exercise migrations against the real application database for tests; use isolated storage for validation.
- Do not reset owner/production data, volumes, or unrelated containers without explicit authorization.
