# syntax=docker/dockerfile:1.7

# ── Vera CLI: build from source ──────────────────────────────────────────────
FROM rust:1-bookworm AS vera_builder

ARG VERA_REPO=https://github.com/ineersa/Vera.git
ARG VERA_BRANCH=localhost

RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*

RUN git clone --branch ${VERA_BRANCH} --depth 1 ${VERA_REPO} /vera-src

WORKDIR /vera-src

RUN --mount=type=cache,target=/vera-src/target \
    --mount=type=cache,target=/usr/local/cargo/registry \
    CARGO_BUILD_JOBS=$(nproc) cargo build --release -p vera-cli \
    && cp target/release/vera /usr/local/bin/vera \
    && vera --version

# ── PHP app ──────────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-bookworm AS frankenphp_upstream

FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    bash \
    file \
    gettext \
    git \
    watchman \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        @composer \
        pdo_sqlite \
        intl \
        opcache \
        zip

# Vera CLI — copy the binary built from source
COPY --from=vera_builder /usr/local/bin/vera /usr/local/bin/vera
RUN vera --version

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY docker/frankenphp/worker.Caddyfile /etc/caddy/worker.Caddyfile
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1

FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
    install-php-extensions xdebug

RUN apt-get update && apt-get install -y --no-install-recommends \
    inotify-tools \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/app.dev.ini /usr/local/etc/php/conf.d/app.dev.ini
COPY docker/frankenphp/watch-and-restart.sh /usr/local/bin/watch-and-restart.sh
RUN chmod +x /usr/local/bin/watch-and-restart.sh

COPY docker/frankenphp/docker-entrypoint-dev.sh /usr/local/bin/docker-entrypoint-dev.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-dev.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-dev.sh"]

FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY composer.* symfony.* ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY . ./

RUN set -eux; \
    mkdir -p var/cache var/log data; \
    touch data/app; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console

# Production UI: Tailwind + AssetMapper (dev uses Castor tailwind/assets tasks instead)
RUN set -eux; \
    php bin/console tailwind:build --env=prod --no-debug; \
    php bin/console asset-map:compile --env=prod --no-debug

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
