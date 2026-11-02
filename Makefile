.PHONY: e2e-stack headers-owner upgrade rollback backup-image backup-key db-backup db-backup-prune db-backup-verify db-restore db-restore-cleanup recurrences-materialize demo-seed demo-extend init install tools up down migrate setup status tls-certificate generate test test-database test-api test-web test-infrastructure perf architecture quality build audit e2e e2e-image e2e-owner e2e-provision e2e-update-snapshots check-http-headers

COMPOSE_ENV := $(if $(wildcard .env.local),.env.local,.env.example)
# Release baked into the app image (GET /api/v1/status); read without Node from the root package.json.
export CADRAN_VERSION := $(shell sed -n 's/^  "version": "\([0-9][0-9.]*\)".*/\1/p' package.json | head -n 1)
COMPOSE := docker compose --env-file $(COMPOSE_ENV)
COMPOSE_DEFAULT := env -u CADRAN_HTTPS_BIND -u CADRAN_HTTPS_PORT -u CADRAN_SERVER_NAME docker compose --env-file .env.example
TOOLS_IMAGE := cadran_tools:local
E2E_IMAGE := cadran_e2e:local
BACKUP_IMAGE := cadran_backup:local
# The browser tests run on their own disposable stack (compose project cadran_e2e: own containers,
# network, volumes and secrets) so they never touch the personal installation on the default stack.
E2E_STACK := cadran_e2e
E2E_PORT ?= 8444
E2E_SECRETS_DIR := var/e2e-secrets
E2E_BASE_URL ?= https://localhost:$(E2E_PORT)
# Address of the default stack, used by the HTTP header check only.
HEADERS_BASE_URL ?= https://localhost:8443
# Throwaway owner of the e2e stack. Test-only, non-secret values for local dev; the CI
# job overrides them. Never point these at a real installation.
E2E_OWNER_EMAIL ?= e2e-owner@example.test
E2E_OWNER_PASSWORD ?= e2e-local-passphrase-not-a-secret
E2E_VISUAL ?=
E2E_DOCKER_RUN := docker run --rm --network host --env CI=1 --env E2E_VISUAL=$(E2E_VISUAL) --env E2E_BASE_URL=$(E2E_BASE_URL) --env E2E_OWNER_EMAIL=$(E2E_OWNER_EMAIL) --env E2E_OWNER_PASSWORD=$(E2E_OWNER_PASSWORD)
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
# Traces and screenshots of a failed run land here, on the host, so they outlive `docker run --rm`.
E2E_RESULTS_DIR := $(CURDIR)/var/e2e-results
TOOLS_DOCKER_RUN := docker run --rm --user $(HOST_UID):$(HOST_GID) --env HOME=/tmp --mount "type=bind,source=$(CURDIR),target=/workspace" --workdir /workspace
TOOLS_RUN := $(TOOLS_DOCKER_RUN) $(TOOLS_IMAGE)
TOOLS_RUN_WITH_DB := $(TOOLS_DOCKER_RUN) --network cadran_internal --mount "type=bind,source=$(CURDIR)/var/docker-secrets/db_password,target=/run/secrets/db_password,readonly" $(TOOLS_IMAGE)
E2E_COMPOSE := env CADRAN_STACK=$(E2E_STACK) CADRAN_SECRETS_DIR=./$(E2E_SECRETS_DIR) CADRAN_HTTPS_PORT=$(E2E_PORT) docker compose --project-name $(E2E_STACK) --env-file .env.example
E2E_TOOLS_RUN_WITH_DB := $(TOOLS_DOCKER_RUN) --network $(E2E_STACK)_internal --mount "type=bind,source=$(CURDIR)/$(E2E_SECRETS_DIR)/db_password,target=/run/secrets/db_password,readonly" $(TOOLS_IMAGE)

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

# Creates the current month's transactions of every auto-create recurrence and
# books the due ones (idempotent). Meant for a daily cron such as
# `5 0 * * *  cd /path/to/cadran && make recurrences-materialize`; pass EMAIL=...
# to target another owner than the provisioned one.
recurrences-materialize: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug cadran:transactions:materialize-recurrences $(EMAIL)

# Dev only: fill an empty workspace with a realistic multi-year ledger. Runs in the
# tooling container (APP_ENV=dev) against the local database; pass EMAIL=... to
# target another owner than the provisioned one.
demo-seed: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug app:demo:seed $(EMAIL)

# Dev only: bring an already seeded workspace up to today (idempotent). Writes
# the open month of the deterministic demo plan and never touches closed months.
demo-extend: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug app:demo:seed --extend $(EMAIL)

# One-time: create the first owner, their isolated workspace, the OWNER
# membership and the account password. Every value is prompted for, so no
# credential lands in the shell history or the host process list.
setup:
	sh scripts/check-local-runtime.sh
	$(COMPOSE) run --rm --no-deps app php apps/api/bin/console cadran:identity:setup

status:
	sh scripts/check-local-runtime.sh
	$(COMPOSE) exec app php /usr/local/bin/healthcheck.php
	@printf 'Version: %s\n' "$$($(COMPOSE) exec -T app printenv CADRAN_VERSION 2>/dev/null || echo unknown)"
	sh scripts/backup.sh health

# Safe upgrade to the tag VERSION=vX.Y.Z: refusals, verified pre-upgrade backup, checkout, build, migration, health.
# IDENTITY=<offline age identity> is required: the new backup is restore-verified before anything changes.
# From an untagged checkout (for example after git pull on main) add FROM=vX.Y.Z, the release that is running.
upgrade:
	sh scripts/upgrade.sh upgrade

# Restores the pre-upgrade backup recorded in var/upgrade/last-upgrade.json, then starts the previous version.
rollback:
	sh scripts/upgrade.sh rollback

backup-image:
	docker build --file docker/backup/Dockerfile --tag $(BACKUP_IMAGE) docker/backup

# Writes the age identity outside the repository and the public recipient to
# var/docker-secrets. IDENTITY defaults to ~/.config/cadran/backup-identity.txt.
backup-key: backup-image
	sh scripts/backup.sh key

# Encrypted pg_dump into CADRAN_BACKUP_DIR (default ./backups). LABEL: manual, daily, pre-upgrade, pre-restore.
db-backup: backup-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	sh scripts/backup.sh create

# Applies the daily/weekly/monthly retention; DRY_RUN=1 only prints the plan.
db-backup-prune: backup-image
	sh scripts/backup.sh prune $(if $(DRY_RUN),--dry-run)

# Restores FILE into the isolated cadran_restore_check database, checks it, drops it. The live database is not touched.
db-backup-verify: backup-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	sh scripts/backup.sh verify

# Verifies FILE, takes a pre-restore backup, then replaces the live database. Needs CONFIRM=restore-cadran.
db-restore:
	sh scripts/backup.sh restore-precheck
	$(MAKE) --no-print-directory backup-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	sh scripts/backup.sh restore

# Lists the cadran_previous_* databases kept after a restore and drops them after an interactive yes.
db-restore-cleanup: backup-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	sh scripts/backup.sh restore-cleanup

tls-certificate:
	sh scripts/check-local-runtime.sh
	mkdir -p var/tls
	$(COMPOSE) cp app:/data/caddy/pki/authorities/local/root.crt var/tls/cadran-local-ca.crt

generate: tools
	$(TOOLS_RUN) pnpm generate:api

test: architecture test-infrastructure test-api test-web

test-database: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console doctrine:database:create --env=test --if-not-exists --no-interaction
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

test-api: test-database
	$(TOOLS_RUN_WITH_DB) composer test --working-dir=apps/api

# Backend performance budgets (NFR-001): seeds 100 000 transactions over 15 years in the test database and prints
# p50/p95 per endpoint. PERF_ASSERT=1 turns the monthly report p95 target (300 ms) into a failure.
perf: test-database
	$(TOOLS_RUN_WITH_DB) env PERF_ASSERT=$(PERF_ASSERT) php apps/api/bin/phpunit --configuration apps/api/phpunit.dist.xml apps/api/performance

test-web: tools
	$(TOOLS_RUN) pnpm test

test-infrastructure: tools
	$(COMPOSE_DEFAULT) config --format json | $(TOOLS_DOCKER_RUN) --interactive --env COMPOSE_CONFIG_PATH=/dev/stdin $(TOOLS_IMAGE) node scripts/test-infrastructure.mjs

architecture:
	sh scripts/test-architecture.sh

quality: architecture test-infrastructure test-database
	$(TOOLS_RUN_WITH_DB) composer quality --working-dir=apps/api
	$(TOOLS_RUN) pnpm quality

build: tools
	$(TOOLS_RUN) pnpm build

audit: tools
	$(TOOLS_RUN) composer audit --working-dir=apps/api --locked
	$(TOOLS_RUN) pnpm audit --audit-level=high

e2e-image:
	docker build --file docker/e2e/Dockerfile --tag $(E2E_IMAGE) .

# The e2e stack: generate its own secrets once, then migrate it.
e2e-stack:
	CADRAN_SECRETS_DIR=$(E2E_SECRETS_DIR) sh scripts/init-local.sh
	$(E2E_COMPOSE) build app db
	$(E2E_COMPOSE) up --detach --wait db
	$(E2E_COMPOSE) run --rm --no-deps app php apps/api/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Create the throwaway e2e owner on the e2e stack (setup --from-env). The dev cache is rebuilt
# first because the console runs with --no-debug (no freshness check) and a stale container
# would miss the new command wiring.
# Idempotent: only the "already done" refusals are tolerated (scripts/tolerate-refusal.sh); every other failure stops the target.
e2e-owner: tools e2e-stack
	$(E2E_TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug cache:clear --env=dev --no-warmup
	@sh scripts/tolerate-refusal.sh 'Initial provisioning has already been completed' $(E2E_TOOLS_RUN_WITH_DB) env CADRAN_SETUP_EMAIL='$(E2E_OWNER_EMAIL)' CADRAN_SETUP_DISPLAY_NAME='E2E Owner' CADRAN_SETUP_WORKSPACE_NAME='E2E Household' CADRAN_SETUP_PASSWORD='$(E2E_OWNER_PASSWORD)' php apps/api/bin/console --no-debug cadran:identity:setup --from-env --no-interaction

# The e2e owner, then fill and top up the demo ledger, all on the e2e stack.
e2e-provision: e2e-owner
	sh scripts/tolerate-refusal.sh 'already holds accounts' $(E2E_TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug app:demo:seed $(E2E_OWNER_EMAIL)
	$(E2E_TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug app:demo:seed --extend $(E2E_OWNER_EMAIL)

# Owner of the default stack for the header check: dev and test stacks only, never personal data.
headers-owner: tools
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait db
	$(MAKE) migrate
	$(TOOLS_RUN_WITH_DB) php apps/api/bin/console --no-debug cache:clear --env=dev --no-warmup
	@sh scripts/tolerate-refusal.sh 'Initial provisioning has already been completed' $(TOOLS_RUN_WITH_DB) env CADRAN_SETUP_EMAIL='$(E2E_OWNER_EMAIL)' CADRAN_SETUP_DISPLAY_NAME='E2E Owner' CADRAN_SETUP_WORKSPACE_NAME='E2E Household' CADRAN_SETUP_PASSWORD='$(E2E_OWNER_PASSWORD)' php apps/api/bin/console --no-debug cadran:identity:setup --from-env --no-interaction

# Asserts the HTTP security headers and the session cookie flags of the running stack (the
# CI image-scan job runs the same target). Two passes: on localhost (no HSTS, then a login
# for the cookie flags) and under a public host name (HSTS). The second pass recreates the
# app container under CADRAN_SERVER_NAME=$(HEADERS_PUBLIC_HOST) and the target puts it back.
# Dev and test stacks only: it provisions the throwaway e2e owner.
HEADERS_PUBLIC_HOST ?= cadran.test
HEADERS_PORT := $(lastword $(subst :, ,$(HEADERS_BASE_URL)))
check-http-headers: headers-owner
	$(COMPOSE) up --detach --wait --build
	$(MAKE) tls-certificate
	@CADRAN_CA_CERT=var/tls/cadran-local-ca.crt E2E_OWNER_EMAIL='$(E2E_OWNER_EMAIL)' E2E_OWNER_PASSWORD='$(E2E_OWNER_PASSWORD)' sh scripts/ci/check-http-headers.sh $(HEADERS_BASE_URL)
	CADRAN_SERVER_NAME=$(HEADERS_PUBLIC_HOST) $(COMPOSE) up --detach --wait app
	CADRAN_CA_CERT=var/tls/cadran-local-ca.crt CHECK_RESOLVE=$(HEADERS_PUBLIC_HOST):$(HEADERS_PORT):127.0.0.1 sh scripts/ci/check-http-headers.sh https://$(HEADERS_PUBLIC_HOST):$(HEADERS_PORT); status=$$?; $(COMPOSE) up --detach --wait app; exit $$status

# Critical-path browser tests against the isolated e2e stack (run make e2e-provision first). Uses the host network
# so the containerised runner reaches the published HTTPS port (Linux hosts).
e2e: e2e-image e2e-stack
	$(E2E_COMPOSE) up --detach --wait --build
	rm -rf $(E2E_RESULTS_DIR)
	mkdir -p $(E2E_RESULTS_DIR)
	$(E2E_DOCKER_RUN) --user $(HOST_UID):$(HOST_GID) --env HOME=/tmp --env E2E_OUTPUT_DIR=/results/run --mount "type=bind,source=$(E2E_RESULTS_DIR),target=/results" $(E2E_IMAGE)

# Visual specs are skipped until 1.0.0 (E2E_VISUAL=1 enables them). Regenerate the visual baselines inside the e2e image (Linux fonts), never on the host.
# tests/e2e/tests is bind-mounted so the new .png files land in the working tree.
e2e-update-snapshots: e2e-image e2e-stack
	$(E2E_COMPOSE) up --detach --wait --build
	$(E2E_DOCKER_RUN) --user $(HOST_UID):$(HOST_GID) --env HOME=/tmp --env E2E_VISUAL=1 --env E2E_OUTPUT_DIR=/tmp/e2e-results --mount "type=bind,source=$(CURDIR)/tests/e2e/tests,target=/e2e/tests" $(E2E_IMAGE) --update-snapshots=all visual.spec.ts
