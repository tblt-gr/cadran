#!/bin/sh
set -eu

if ! command -v git >/dev/null 2>&1; then
  printf '%s\n' 'Git is required to enumerate versioned documentation.' >&2
  exit 1
fi

git ls-files --cached --others --exclude-standard -z -- '*.md' | xargs -0 pnpm exec markdownlint-cli2
