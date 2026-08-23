.PHONY: init generate test test-api test-web architecture quality build audit

init:
	@test -f apps/api/.env || php -r 'printf("APP_ENV=dev\nAPP_SECRET=%s\n", bin2hex(random_bytes(32)));' > apps/api/.env
	composer install --working-dir=apps/api
	pnpm install --frozen-lockfile
	$(MAKE) generate

generate:
	pnpm generate:api

test: architecture test-api test-web

test-api:
	composer test --working-dir=apps/api

test-web:
	pnpm test

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
