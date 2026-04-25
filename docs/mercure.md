# Mercure setup notes

This template uses Mercure for realtime browser updates. The hub is embedded in FrankenPHP/Caddy.

## Environment variables

- `MERCURE_URL`: internal publish URL used by the app.
- `MERCURE_PUBLIC_URL`: browser-facing URL.
- `MERCURE_JWT_SECRET`: Symfony Mercure bundle secret.
- `MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_SUBSCRIBER_JWT_KEY`: Caddy/Mercure signing keys.

## Local defaults

`compose.yaml` ships with development-safe defaults for JWT keys. Replace them in production.

## Production notes

Generate a secure key:

```bash
openssl rand -base64 32
```

Set both publisher/subscriber keys in `.env.prod.local`.

## Caddy transport (Bolt)

Mercure transport is configured via `MERCURE_EXTRA_DIRECTIVES`, for example:

```caddyfile
transport bolt {
  path /data/mercure.db
}
```
