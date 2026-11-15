#!/bin/sh
# Runs the production license gate (SEC-007) and writes the merged report.
# Usage: check-licenses.sh [report.json]   (default: a temporary file)
# Requires installed dependencies: pnpm install and composer install under apps/api.
set -eu

report="${1:-}"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
[ -n "$report" ] || report="$work/licenses.json"

pnpm licenses list --prod --json > "$work/pnpm.json"
composer licenses --no-dev --format=json --working-dir=apps/api > "$work/composer.json"
node scripts/ci/check-licenses.mjs "$work/pnpm.json" "$work/composer.json" "$report"
