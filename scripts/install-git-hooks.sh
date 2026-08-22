#!/bin/sh
set -eu

script_dir=$(CDPATH= cd "$(dirname "$0")" && pwd)
project_dir="${CADRAN_PROJECT_DIR:-$(CDPATH= cd "$script_dir/.." && pwd)}"

if [ -n "${CI:-}" ] || ! command -v git >/dev/null 2>&1 || [ ! -e "$project_dir/.git" ]; then
  exit 0
fi

if ! git -C "$project_dir" rev-parse --git-dir >/dev/null 2>&1; then
  exit 0
fi

current_path=$(git -C "$project_dir" config --local --get core.hooksPath || true)
default_hooks_path="$(git -C "$project_dir" rev-parse --absolute-git-dir)/hooks"

case "$current_path" in
  '' | .githooks | */.githooks) ;;
  "$default_hooks_path")
    printf '%s\n' "Reclaiming core.hooksPath from '$current_path'." >&2
    ;;
  *)
    printf '%s\n' "core.hooksPath is set to '$current_path'; leaving it untouched." >&2
    printf '%s\n' "Run 'git config core.hooksPath .githooks' to enable repository hooks." >&2
    exit 0
    ;;
esac

git -C "$project_dir" config core.hooksPath .githooks
printf '%s\n' 'Git hooks enabled (core.hooksPath=.githooks).'

if ! command -v pnpm >/dev/null 2>&1; then
  exit 0
fi

if (cd "$project_dir" && pnpm exec lefthook install --force >/dev/null 2>&1); then
  printf '%s\n' 'Lefthook hooks generated in .githooks.'
else
  printf '%s\n' "Lefthook install failed; run 'pnpm exec lefthook install --force' manually." >&2
fi
