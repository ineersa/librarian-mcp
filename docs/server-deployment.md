# Server deployment (FrankenPHP, Docker, TLS, domain)

This guide assumes `compose.yaml` + `compose.prod.yaml`. Prefer Castor production tasks discovered with `vendor/bin/castor list prod` over raw compose when those tasks exist.

## Framework upgrade note

Symfony **8.2.0-BETA1** was owner-approved on 2026-11-12 for this upgrade because stable 8.2 was unavailable. Production images must satisfy PHP 8.4+ / project PHP 8.5 and extension requirements recorded by main. Exact dependency pins and smoke proof are owned by main/runtime — this document does not claim validation passed.

Preserve current deployment topology (loopback app port + host nginx TLS by default) unless a later plan replaces it. Do not use the dependency upgrade as permission to rewrite the domain model or Vera workflow.

## Default mode (recommended)

By default, app HTTP is published to loopback only:

- `127.0.0.1:${APP_HTTP_PORT:-8080}:80`

Use host nginx (or another reverse proxy) for public TLS on 443.

## One-time setup

1. Copy prod local env file:

   ```bash
   cp .env.prod.local.dist .env.prod.local
   ```

2. Edit `.env.prod.local` and set real production values:

   - `APP_SECRET`
   - `DEFAULT_URI` (for example `https://librarian.example.com`)
   - `MERCURE_JWT_SECRET`, `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`
   - `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`
   - `SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port`

3. If host port `8080` is already used by another stack, override the published app port in `.env`:

   ```bash
   APP_HTTP_BIND=127.0.0.1
   APP_HTTP_PORT=8081
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

6. Create an admin user, then configure nginx to proxy `http://127.0.0.1:${APP_HTTP_PORT:-8080}`.

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

- `compose.prod.yaml` loads `.env.prod.local` with `env_file`.
- Caddy reads Mercure keys via `{env.MERCURE_*}`.
- Symfony environment is baked by `composer dump-env prod` during image build.
- Do not exercise migrations against the real application database for tests; use isolated storage for validation.
