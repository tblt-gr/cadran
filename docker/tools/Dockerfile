FROM node:22.22.0-bookworm-slim AS node

FROM composer:2.9 AS composer

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN apt-get update \
    && apt-get install --no-install-recommends --yes git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql \
    && ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && npm install --global pnpm@10.32.1 \
    && npm cache clean --force

COPY docker/tools/entrypoint.sh /usr/local/bin/cadran-tools-entrypoint

USER 65534:65534
WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/cadran-tools-entrypoint"]
