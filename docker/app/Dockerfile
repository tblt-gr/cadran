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
    --ignore-platform-req=php \
    --ignore-platform-req=ext-bcmath

FROM dunglas/frankenphp:1.13.1-php8.5-trixie@sha256:ec7839c3cf2983e363d672d05d10d401c8414bc6f119f7f7e2884719530a4b08 AS php_extensions

# The compiler toolchain that install-php-extensions pulls in lives only in this
# stage: the runtime image receives the built modules and nothing else.
RUN install-php-extensions pdo_pgsql bcmath \
    && mkdir -p /out/extensions /out/conf.d \
    && cp "$(php -r 'echo ini_get("extension_dir");')"/pdo_pgsql.so "$(php -r 'echo ini_get("extension_dir");')"/bcmath.so /out/extensions/ \
    && cp "$PHP_INI_DIR"/conf.d/docker-php-ext-pdo_pgsql.ini "$PHP_INI_DIR"/conf.d/docker-php-ext-bcmath.ini /out/conf.d/

FROM dunglas/frankenphp:1.13.1-php8.5-trixie@sha256:ec7839c3cf2983e363d672d05d10d401c8414bc6f119f7f7e2884719530a4b08 AS runtime

# The FrankenPHP base image ships a compiler toolchain, kernel headers, curl and
# perl for building extensions. None of them is used at run time, so they are
# purged to leave an attacker no tooling and the scanner nothing to flag.
# libpq5 is the only shared library the extensions need. The security upgrade
# picks up Debian fixes published after the base image digest.
RUN --mount=type=bind,from=php_extensions,source=/out,target=/out \
    apt-get update \
    && apt-get install --no-install-recommends --yes libpq5 \
    && apt-get purge --yes --auto-remove \
        autoconf binutils cpp dpkg-dev g++ gcc make libc6-dev linux-libc-dev curl perl \
    && apt-get upgrade --yes \
    && rm -rf /var/lib/apt/lists/* \
    && cp /out/extensions/*.so "$(php -r 'echo ini_get("extension_dir");')/" \
    && cp /out/conf.d/*.ini "$PHP_INI_DIR/conf.d/" \
    && setcap -r /usr/local/bin/frankenphp \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /app/apps/api/var /app/apps/web/dist /data /config \
    && chown -R www-data:www-data /app /data /config

WORKDIR /app

COPY --chown=www-data:www-data apps/api apps/api
COPY --from=php_dependencies --chown=www-data:www-data /build/vendor apps/api/vendor
COPY --from=web_build --chown=www-data:www-data /workspace/apps/web/dist apps/web/dist
COPY --chown=www-data:www-data docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/zz-cadran-security.ini $PHP_INI_DIR/conf.d/zz-cadran-security.ini
COPY docker/app/entrypoint.sh /usr/local/bin/cadran-entrypoint
COPY docker/app/healthcheck.php /usr/local/bin/healthcheck.php

# Declared last so a new release only invalidates the layers below it.
ARG CADRAN_VERSION=unknown
ENV CADRAN_VERSION=${CADRAN_VERSION}

USER www-data

ENTRYPOINT ["/usr/local/bin/cadran-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
