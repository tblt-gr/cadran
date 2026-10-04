FROM node:22.22.0-bookworm-slim@sha256:dd9d21971ec4395903fa6143c2b9267d048ae01ca6d3ea96f16cb30df6187d94 AS web_build

ENV LEFTHOOK=0
WORKDIR /workspace

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/web/package.json apps/web/package.json
COPY packages/api-client/package.json packages/api-client/package.json

RUN corepack enable \
    && corepack prepare pnpm@10.32.1 --activate \
    && pnpm install --frozen-lockfile --ignore-scripts

COPY apps/api/openapi apps/api/openapi
COPY apps/web apps/web
COPY packages/api-client packages/api-client
# `tsc -b` typechecks colocated specs. Some of them read shared contract and
# reference cases at the repository root, so those folders have to be in this
# stage even though they never reach the runtime image.
COPY tests/contracts tests/contracts
COPY tests/fixtures tests/fixtures

RUN pnpm generate:api \
    && pnpm --filter @cadran/web build

FROM composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d AS php_dependencies

WORKDIR /build

COPY apps/api/composer.json apps/api/composer.lock apps/api/symfony.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-req=php

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:d502c4823f75f7738b463919ae993a6a4c7ccccab034e3e3020d3b9d042ec83e AS runtime

# Install patched Bookworm packages that are still stale in the FrankenPHP
# base image. Keep the list explicit so unrelated runtime packages do not move.
RUN apt-get update \
    && apt-get install --no-install-recommends --yes --only-upgrade \
        libpcre2-8-0 \
        libssh2-1 \
        linux-libc-dev \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql \
    && setcap -r /usr/local/bin/frankenphp \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /app/apps/api/var /app/apps/web/dist /data /config \
    && chown -R www-data:www-data /app /data /config

WORKDIR /app

COPY --chown=www-data:www-data apps/api apps/api
COPY --from=php_dependencies --chown=www-data:www-data /build/vendor apps/api/vendor
COPY --from=web_build --chown=www-data:www-data /workspace/apps/web/dist apps/web/dist
COPY --chown=www-data:www-data docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/entrypoint.sh /usr/local/bin/cadran-entrypoint
COPY docker/app/healthcheck.php /usr/local/bin/healthcheck.php

USER www-data

ENTRYPOINT ["/usr/local/bin/cadran-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
