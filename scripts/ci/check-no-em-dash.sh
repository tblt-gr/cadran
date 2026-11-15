#!/bin/sh
# Refuses the em dash (U+2014) in tracked files. Released migrations and lockfiles are exempt.
set -eu

dash=$(printf '\342\200\224')

if git grep -n -I "$dash" -- . ':!apps/api/migrations' ':!pnpm-lock.yaml' ':!apps/api/composer.lock'; then
  echo "The em dash is forbidden: use a comma, a colon or ' - ' instead." >&2
  exit 1
fi
