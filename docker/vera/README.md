# docker/vera/

Vera config mounted into the PHP container at `/root/.vera`.

- `config.json` — API backend + tuning defaults (committed)
- `credentials.json` — API keys (all `not-needed` for local llama.cpp, committed)

The compose env vars (`EMBEDDING_MODEL_BASE_URL`, etc.) override the
`localhost` endpoints to `host.docker.internal` at runtime, so the committed
config works both on host and inside Docker.

Vera writes runtime state (`lib/`, `models/`, `update-check.json`) into this
directory at runtime — those are gitignored.
