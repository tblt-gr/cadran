.PHONY: upgrade rollback backup-image backup-key db-backup db-backup-prune db-backup-verify db-restore db-restore-cleanup demo-seed demo-extend init install tools up down migrate setup status tls-certificate generate test test-database test-api test-web test-infrastructure perf architecture quality build audit e2e e2e-image

COMPOSE_ENV := $(if $(wildcard .env.local),.env.local,.env.example)
# Release baked into the app image (GET /api/v1/status); read without Node from the root package.json.
export CADRAN_VERSION := $(shell sed -n 's/^  "version": "\([0-9][0-9.]*\)".*/\1/p' package.json | head -n 1)
COMPOSE := docker compose --env-file $(COMPOSE_ENV)
COMPOSE_DEFAULT := env -u CADRAN_HTTPS_BIND -u CADRAN_HTTPS_PORT -u CADRAN_SERVER_NAME docker compose --env-file .env.example
TOOLS_IMAGE := cadran_tools:local
E2E_IMAGE := cadran_e2e:local
BACKUP_IMAGE := cadran_backup:local
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

# Critical-path browser tests against the full local stack. Uses the host network
# so the containerised runner reaches the published HTTPS port (Linux hosts).
e2e: e2e-image
	sh scripts/check-local-runtime.sh
	$(COMPOSE) up --detach --wait
	$(MAKE) migrate
	docker run --rm --network host --env CI=1 --env E2E_BASE_URL=$(E2E_BASE_URL) $(E2E_IMAGE)
