#!/bin/sh
# Fails when a PHP class or React component outgrows its size threshold.
# Usage: check-file-sizes.sh [root] [allowlist]
# Files are only counted with wc -l, never executed or sourced.
set -eu

root=${1:-.}
allowlist=${2:-$root/scripts/file-size-allowlist.txt}
php_limit=300
tsx_limit=250

list=$(mktemp)
allowed=$(mktemp)
trap 'rm -f "$list" "$allowed"' EXIT

{
  [ ! -d "$root/apps/api/src" ] || (cd "$root" && find apps/api/src -type f -name '*.php')
  [ ! -d "$root/apps/web/src" ] || (cd "$root" && find apps/web/src -type f -name '*.tsx' ! -name '*.spec.tsx' ! -name '*.test.tsx')
} | LC_ALL=C sort >"$list"

# Normalise the allowlist: drop comments and blank lines.
grep -v -e '^[[:space:]]*#' -e '^[[:space:]]*$' "$allowlist" >"$allowed" || true

status=0

# The allowlist must stay sorted by path so diffs stay reviewable.
sorted_paths=$(awk '{print $2}' "$allowed")
if [ "$sorted_paths" != "$(printf '%s\n' "$sorted_paths" | LC_ALL=C sort)" ]; then
  printf '%s\n' "$allowlist: entries must be sorted by path." >&2
  status=1
fi

while IFS= read -r file; do
  [ -n "$file" ] || continue
  case "$file" in
    *.php) limit=$php_limit ;;
    *) limit=$tsx_limit ;;
  esac
  lines=$(wc -l <"$root/$file" | tr -d ' ')
  entry=$(awk -v f="$file" '$2 == f {print $1; exit}' "$allowed")
  if [ -z "$entry" ]; then
    if [ "$lines" -gt "$limit" ]; then
      printf '%s\n' "$file: $lines lines, limit $limit. Split it by responsibility, or add '<ceiling> $file <reason>' to scripts/file-size-allowlist.txt with a justification." >&2
      status=1
    fi
  elif [ "$lines" -gt "$entry" ]; then
    printf '%s\n' "$file: $lines lines, recorded ceiling $entry. Extract the new code instead of growing the file." >&2
    status=1
  elif [ "$lines" -le "$limit" ]; then
    printf '%s\n' "$file: $lines lines, now within the limit $limit. Remove its stale entry from scripts/file-size-allowlist.txt." >&2
    status=1
  fi
done <"$list"

# Entries pointing at files that no longer exist are stale too.
while read -r _ceiling path _reason; do
  [ -n "$path" ] || continue
  if ! grep -qxF "$path" "$list"; then
    printf '%s\n' "$path: allowlisted but not found. Remove its stale entry from scripts/file-size-allowlist.txt." >&2
    status=1
  fi
done <"$allowed"

if [ "$status" -eq 0 ]; then
  printf '%s\n' "File sizes are within their thresholds or recorded ceilings."
fi
exit "$status"
