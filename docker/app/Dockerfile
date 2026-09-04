FROM node:22.22.0-bookworm-slim AS web_build

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
# `tsc -b` typechecks colocated specs. One of them reads the shared rate-scale
# bound cases at the repository root, so that folder has to be in this stage
# even though it never reaches the runtime image.
COPY tests/contracts tests/contracts

RUN pnpm generate:api \
    && pnpm --filter @cadran/web build

FROM composer:2.9 AS php_dependencies

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

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm AS runtime

RUN install-php-extensions pdo_pgsql \
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
