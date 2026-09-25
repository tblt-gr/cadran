FROM node:22.22.0-bookworm-slim@sha256:dd9d21971ec4395903fa6143c2b9267d048ae01ca6d3ea96f16cb30df6187d94 AS node

FROM composer:2.9@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d AS composer

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:d502c4823f75f7738b463919ae993a6a4c7ccccab034e3e3020d3b9d042ec83e

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
