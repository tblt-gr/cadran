.PHONY: init up down migrate status tls-certificate generate test test-api test-web test-infrastructure architecture quality build audit

COMPOSE_ENV := $(if $(wildcard .env.local),.env.local,.env.example)
COMPOSE := docker compose --env-file $(COMPOSE_ENV)

init:
	sh scripts/init-local.sh
	$(COMPOSE) build
	$(COMPOSE) up --detach --wait db
	$(MAKE) migrate
	$(COMPOSE) up --detach --wait app

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

generate:
	pnpm generate:api

test: architecture test-infrastructure test-api test-web

test-api:
	composer test --working-dir=apps/api

test-web:
	pnpm test

test-infrastructure:
	node scripts/test-infrastructure.mjs

architecture:
	sh scripts/test-architecture.sh

quality: architecture
	composer quality --working-dir=apps/api
	pnpm quality

build:
	pnpm build

audit:
	composer audit --working-dir=apps/api --locked
	pnpm audit
