.PHONY: init install tools up down migrate status tls-certificate generate test test-api test-web test-infrastructure architecture quality build audit e2e e2e-image

COMPOSE_ENV := $(if $(wildcard .env.local),.env.local,.env.example)
COMPOSE := docker compose --env-file $(COMPOSE_ENV)
COMPOSE_DEFAULT := env -u CADRAN_HTTPS_BIND -u CADRAN_HTTPS_PORT -u CADRAN_SERVER_NAME docker compose --env-file .env.example
TOOLS_IMAGE := cadran_tools:local
E2E_IMAGE := cadran_e2e:local
# Host-published address of the running stack. Override when .env.local changes the port.
E2E_BASE_URL ?= https://localhost:8443
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
TOOLS_DOCKER_RUN := docker run --rm --user $(HOST_UID):$(HOST_GID) --env HOME=/tmp --mount "type=bind,source=$(CURDIR),target=/workspace" --workdir /workspace
TOOLS_RUN := $(TOOLS_DOCKER_RUN) $(TOOLS_IMAGE)
TOOLS_RUN_WITH_DB := $(TOOLS_DOCKER_RUN) --network cadran_internal --mount "type=bind,source=$(CURDIR)/var/docker-secrets/db_password,target=/run/secrets/db_password,readonly" $(TOOLS_IMAGE)

init:
	sh scripts/init-local.sh
	$(MAKE) install
	$(MAKE) generate
	$(COMPOSE) build
	$(COMPOSE) up --detach --wait db
	$(MAKE) migrate
	$(COMPOSE) up --detach --wait app

tools:
	docker build --file docker/tools/Dockerfile --tag $(TOOLS_IMAGE) .

install: tools
	$(TOOLS_RUN) composer install --working-dir=apps/api --no-interaction --no-progress --prefer-dist --no-scripts
	$(TOOLS_RUN) pnpm install --frozen-lockfile --ignore-scripts
	$(TOOLS_RUN) sh scripts/install-git-hooks.sh

up:
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait

down:
	$(COMPOSE) down

migrate:
	sh scripts/check-local-runtime.sh
	$(COMPOSE) run --rm --no-deps app php apps/api/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

status:
	sh scripts/check-local-runtime.sh
	$(COMPOSE) exec app php /usr/local/bin/healthcheck.php

tls-certificate:
	sh scripts/check-local-runtime.sh
	mkdir -p var/tls
	$(COMPOSE) cp app:/data/caddy/pki/authorities/local/root.crt var/tls/cadran-local-ca.crt

generate: tools
	$(TOOLS_RUN) pnpm generate:api

test: architecture test-infrastructure test-api test-web

test-api: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
	$(TOOLS_RUN_WITH_DB) composer test --working-dir=apps/api

test-web: tools
	$(TOOLS_RUN) pnpm test

test-infrastructure: tools
	$(COMPOSE_DEFAULT) config --format json | $(TOOLS_DOCKER_RUN) --interactive --env COMPOSE_CONFIG_PATH=/dev/stdin $(TOOLS_IMAGE) node scripts/test-infrastructure.mjs

architecture:
	sh scripts/test-architecture.sh

quality: architecture test-infrastructure tools
	$(TOOLS_RUN) composer quality --working-dir=apps/api
	$(TOOLS_RUN) pnpm quality

build: tools
	$(TOOLS_RUN) pnpm build

audit: tools
	$(TOOLS_RUN) composer audit --working-dir=apps/api --locked
	$(TOOLS_RUN) pnpm audit --audit-level=high

e2e-image:
	docker build --file docker/e2e/Dockerfile --tag $(E2E_IMAGE) .

# Critical-path browser tests against the full local stack. Uses the host network
# so the containerised runner reaches the published HTTPS port (Linux hosts).
e2e: e2e-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait
	$(MAKE) migrate
	docker run --rm --network host --env CI=1 --env E2E_BASE_URL=$(E2E_BASE_URL) $(E2E_IMAGE)
