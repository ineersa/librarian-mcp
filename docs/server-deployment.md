# Server deployment (FrankenPHP, Docker, TLS, domain)

This guide assumes `compose.yaml` + `compose.prod.yaml`.

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
   - `DEFAULT_URI` (for example `https://reader.example.com`)
   - `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR`
   - `SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-host,x-forwarded-proto,x-forwarded-port`

3. If host port `8080` is already used by another stack, override the published app port in `.env`:

   ```bash
   APP_HTTP_BIND=127.0.0.1
   APP_HTTP_PORT=8081
   ```

   Keep it loopback-only (`127.0.0.1`) when nginx is your public entrypoint.

4. Build and start:

   ```bash
   castor prod:build
   castor prod:up
   ```

5. Run migrations:

   ```bash
   castor prod:console "doctrine:migrations:migrate --no-interaction"
   ```

6. Configure nginx to proxy `http://127.0.0.1:${APP_HTTP_PORT:-8080}`.

## Nginx example (host machine)

Replace `reader.example.com` with your real domain and keep proxy port in sync with `APP_HTTP_PORT`.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name reader.example.com;

    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    server_name reader.example.com;

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

1. Verify DNS for your real domain points to this server:

   ```bash
   dig +short A reader.example.com
   dig +short AAAA reader.example.com
   ```

2. Ensure inbound port 80 is open (firewall and provider security group).

3. Request cert for the real domain (not a placeholder):

   ```bash
   certbot --nginx -d reader.example.com
   ```

4. If IPv6 is not configured on this host, remove broken `AAAA` records before retrying.

5. Reload nginx and verify:

   ```bash
   nginx -t && systemctl reload nginx
   curl -I https://reader.example.com
   ```

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
