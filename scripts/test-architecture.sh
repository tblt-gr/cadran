#!/bin/sh
set -eu

php scripts/check-architecture.php apps/api/src

result_file=$(mktemp)
trap 'rm -f "$result_file"' EXIT

if php scripts/check-architecture.php tests/architecture/fixtures/invalid >"$result_file" 2>&1; then
  printf '%s\n' 'The representative forbidden dependency was not rejected.' >&2
  exit 1
fi

if ! grep -q 'Application must not depend on Infrastructure' "$result_file"; then
  printf '%s\n' 'The boundary checker failed without the expected diagnostic.' >&2
  exit 1
fi

printf '%s\n' 'The representative forbidden dependency was rejected.'
