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
   - `MCP_AUTH_REQUIRED` (`1` by default: require an admin-issued token; set `0` only for trusted-network anonymous MCP access). Admin login stays protected in either mode. Restart the stack after changing this setting.
   - `HATFIELD_USER_HOME` (required)
   - `HATFIELD_APP_ROOT` when settings use `%kernel.project_dir%`
   - `HATFIELD_PATH_MAPPINGS` when host/service path prefixes differ
   - `CLAUDE_PROJECTS_PATH` (optional): absolute host Claude Code transcript root, e.g. `/home/me/.claude/projects`; mounted read-only at the same path. Empty disables Claude transcripts
   - `DATABASE_URL` (shared Doctrine + official Postgres store)
   - `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` (must match `DATABASE_URL`; password is needed for host Compose interpolation; do not commit secrets)
   - `CODERANK_URL`, `CODERANK_MODEL`, `CODERANK_ARTIFACT`
   - `BGE_URL`, `BGE_MODEL` (API-mode defaults; Settings can override or switch to Built-in)
   - optional `EMBEDDING_API_KEY`, `RERANKER_API_KEY` (sent only in API mode, never stored in the database)
   - optional `COMPOSE_PROFILES=models` and `LLAMA_THREADS` to run the built-in llama.cpp model servers
   - `INDEX_INTERVAL`, `LOCK_DSN`
   - `MERCURE_JWT_SECRET`, `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`
   - `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`
   - `SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port`

   Production compose receives `--env-file .env.prod.local`. Production web and worker must share the same Postgres database and lock store. Keep lock DSN overrides on shared storage, not container-local paths. See `.env.prod.local.dist`.

3. Configure the published app port and read-only project bind in `.env.prod.local` (Castor loads it for both Compose interpolation and container environment):

   ```bash
   APP_HTTP_BIND=127.0.0.1
   APP_HTTP_PORT=8081
   PROJECTS_HOST_PATH=/absolute/host/projects
   PROJECTS_CONTAINER_PATH=/absolute/host/projects
   ```

   Using the same source and target lets the registration form accept host absolute paths. Omit `PROJECTS_CONTAINER_PATH` to keep the default `/projects` target.

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

Optional: maintain an ignored `compose.sources.yaml` for additional mounts of user/global/custom Hatfield settings or data. Mounts are read-only; database connections remain `mode=ro`. Castor layers it for **dev/prod only**, never for the isolated browser stack. In production add them to both consumers, `messenger` and `messenger-om` (development: `worker` and `om-worker`), since memory generation reads session journals; see [source mounts](project-sources.md#docker-source-mounts).

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

If no other service owns host 80/443, set in `.env.prod.local`:

```bash
APP_HTTP_BIND=0.0.0.0
APP_HTTP_PORT=80
```

Then publish host 443 with a local compose override.

## Runtime notes

- On the `om` queue, retryable observe/reflect failures get up to ten native Messenger retries: 30 seconds initially, doubling with 10% jitter and a nine-minute cap per delay. The nominal schedule spans about an hour, not a hard deadline. A busy per-session lock instead defers the message for a fresh random 60–120 seconds without sleeping in the worker or changing its failure count.


- `compose.prod.yaml` loads `.env.prod.local` with `env_file` and Castor passes `--env-file .env.prod.local`.
- Caddy reads Mercure keys via `{env.MERCURE_*}`.
- Symfony environment from `.env` + `.env.prod` is baked by `composer dump-env prod` during image build. `.env.prod.local` stays out of the build context and is injected at runtime.
- Castor loads `.env`, `.env.prod` and `.env.prod.local` for compose interpolation, preserving `COMPOSE_PROJECT_NAME=librarian-mcp-prod`. It does not assign a new hashed production project name.
- Project sources are mounted read-only; source database connections remain `mode=ro`, with no source-table, journal or transcript writes. Mounting the whole home (`PROJECTS_HOST_PATH=/home/<user>`) is supported and also covers `~/.claude/projects`.
- Postgres stays on the private network with no host ports. `messenger` consumes Messenger `scheduler_cron` and `cron` (daily backups, prioritized; see [backups.md](backups.md)), then `async` and `scheduler_default`; `messenger-om` consumes `om_reflect` then `om` (memory generation; waiting Reflector jobs run before the next observe job).
- Web, Messenger and PostgreSQL use `restart: unless-stopped`. The Messenger-only container disables the image's Caddy HTTP health check because it does not run an HTTP server; a running worker is not an HTTP health-check result.
- Scheduler uses `CallbackMessageProvider` for automatic source discovery followed by incremental indexing (`INDEX_INTERVAL`, default 60s). Check timestamps advance even when unchanged memories do not need rebuilding.
- Live admin status uses private Mercure + Turbo Streams with native topic-scoped authorization cookies; anonymous hub subscriptions are disabled. Use the configured `MERCURE_PUBLIC_URL` origin for admin access so the app and hub can share the cookie. If a reverse proxy is added, preserve long-lived, unbuffered event-stream responses.
- **Operations → Logs** shows a curated, admin-only view of recent application diagnostics. Production stderr is unchanged. The shared `data-prod/operations/` directory retains up to three daily files per source; the UI reads only bounded tails. Delayed retry details are a snapshot on dashboard load based on retry events captured since deployment; the live cards remain the source of truth for queue counts.
- **Operations → OM model** switches the shared Observer/Reflector/Dropper selection without restarting workers. New ingest pins the non-secret choice onto jobs; queued and in-flight work keeps its original selection. Cloud presets require `OPENCODE_API_KEY` / `ZAI_API_KEY` in `.env.prod.local` and send OM content off-host. The vLLM preset (`vllm/Qwen3.8-27B`, reasoning Low / Medium / Extra high) is listed only when `VLLM_BASE_URL` is set in `.env.prod.local`, requires `VLLM_API_KEY`, and sends OM content to that server. The Inkling preset (`hyperinkling/inkling-2-mini`, reasoning Off through Max, context up to 268,544 tokens) works the same way with `INKLING_BASE_URL` / `INKLING_API_KEY`.
- **Settings** holds the per-source OM token limits (Claude Code and Hatfield). Without a saved row both use `OM_REFLECT_AFTER_OBSERVATION_TOKENS` / `OM_OBSERVATIONS_MAX_TOKENS`; saved values apply to queued jobs too. See [MCP.md §8](MCP.md#8-observational-memory-api-plan-09-contract-v1).
- **Settings → Backups** can turn off the scheduled daily backup; forced runs still work. See [backups.md](backups.md#schedule-status-and-reruns).
- **Settings → Embeddings / Reranker** chooses Built-in (the optional `embeddings` / `reranker` services, `COMPOSE_PROFILES=models`) or an API URL and model per model; empty API fields use the env defaults, and the existing row keeps API mode on them. Saving probes only a changed endpoint: it must answer `GET /v1/models`, and a changed embeddings endpoint must reproduce a stored chunk's vector (cosine ≥ 0.99), so a different model is rejected instead of mixing vectors into existing generations. Changes apply to the next request or Messenger message; no restart. The web app has no Docker socket and does not start or stop the model services. See [setup.md](setup.md#built-in-models-docker-without-host-llamacpp).
- **Projects → Backfill sessions** queues observe jobs for a registered project's existing Claude transcripts under `CLAUDE_PROJECTS_PATH` and its discovered Hatfield session journals. After deploying this change, recreate `messenger-om` so it consumes `om_reflect`; Reflector jobs routed there are not consumed by an old `messenger:consume om` process.
- Registration deletion removes owned derived indexes only; never source trees.
- `data-prod/` and private production settings are ignored; preserve them across builds and checkout changes.
- Do not exercise migrations against the real application database for tests; use isolated storage for validation.
- Do not reset owner/production data, volumes, or unrelated containers without explicit authorization.
- Old-checkout cleanup is separate from deployment. Never run `down --volumes` from an old checkout: the stable production Compose project and volumes may already be serving the new checkout. Verify current container bind mounts before removing any old directory.
