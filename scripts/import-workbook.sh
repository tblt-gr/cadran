#!/usr/bin/env bash
# One-shot import of the yearly budget workbooks through the tooling container (IMP-006, transitional: removed by IMP-007).
#   make import-workbook EMIT_MAPPING=/abs/mapping.yaml FILES="/abs/2023.xlsx '/abs/2024 sample.xlsx'"
#   make import-workbook FILE=/abs/2025.xlsx MAPPING=/abs/mapping.yaml [DRY_RUN=1] [VERBOSE=1] [EMAIL=...] [YEAR=2025]
# Every path must lie outside the repository: workbooks and mappings hold real financial data. FILES takes shell-style
# quoting for a name with spaces. VERBOSE=1 needs DRY_RUN=1 and prints labels and amounts to the terminal only.
# The container recipe mirrors TOOLS_RUN_WITH_DB in the Makefile; keep both in sync.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
image=${TOOLS_IMAGE:-cadran_tools:local}

fail() {
  printf '%s\n' "$1" >&2
  exit 2
}

# Resolves symlinks and `..`, so a link into the repository is refused like a path inside it.
resolve() {
  realpath -m -- "$1"
}

refuse_inside_repository() {
  case "$1/" in
    "$root"/*) fail "Refused: a workbook, a mapping and a skeleton hold real data and must stay outside the repository." ;;
  esac
}

check_name() {
  case "$1" in
    *,* | *:* | *$'\n'*) fail "Refused: a path may not contain a comma, a colon or a line break." ;;
  esac
}

files=()
if [ -n "${FILES:-}" ]; then
  # xargs honours shell quoting without evaluating anything.
  while IFS= read -r -d '' token; do files+=("$token"); done < <(printf '%s' "$FILES" | xargs printf '%s\0')
fi
if [ -n "${FILE:-}" ]; then
  files+=("$FILE")
fi

mounts=()
arguments=()
index=0
for file in "${files[@]}"; do
  real=$(resolve "$file")
  refuse_inside_repository "$real"
  check_name "$real"
  [ -f "$real" ] && [ -r "$real" ] || fail "Refused: a workbook does not exist or is not readable."
  index=$((index + 1))
  mounts+=(--mount "type=bind,source=$real,target=/import/in$index/$(basename "$real"),readonly")
  arguments+=("/import/in$index/$(basename "$real")")
done

if [ -n "${EMIT_MAPPING:-}" ]; then
  [ "${#files[@]}" -ge 1 ] || fail "Give at least one workbook in FILES."
  target=$(resolve "$EMIT_MAPPING")
  refuse_inside_repository "$target"
  check_name "$target"
  [ -d "$(dirname "$target")" ] || fail "Refused: the directory of EMIT_MAPPING does not exist."
  mounts+=(--mount "type=bind,source=$(dirname "$target"),target=/import/out")
  arguments+=(--emit-mapping "/import/out/$(basename "$target")")
else
  [ "${#files[@]}" -eq 1 ] || fail "Give exactly one workbook in FILE."
  [ -n "${MAPPING:-}" ] || fail "Give MAPPING, or EMIT_MAPPING to write a skeleton."
  mapping=$(resolve "$MAPPING")
  refuse_inside_repository "$mapping"
  check_name "$mapping"
  [ -f "$mapping" ] && [ -r "$mapping" ] || fail "Refused: the mapping does not exist or is not readable."
  mounts+=(--mount "type=bind,source=$mapping,target=/import/mapping/$(basename "$mapping"),readonly")
  arguments+=(--mapping "/import/mapping/$(basename "$mapping")")
  [ "${DRY_RUN:-}" = 1 ] && arguments+=(--dry-run)
  [ "${VERBOSE:-}" = 1 ] && arguments+=(--show-details)
  [ -n "${EMAIL:-}" ] && arguments+=(--email "$EMAIL")
  [ -n "${YEAR:-}" ] && arguments+=(--year "$YEAR")
fi

# The cache of a --no-debug run is never invalidated: clear it so a command added since the last run is found.
exec docker run --rm --user "$(id -u):$(id -g)" --env HOME=/tmp \
  --mount "type=bind,source=$root,target=/workspace" --workdir /workspace \
  --network cadran_internal \
  --mount "type=bind,source=$root/var/docker-secrets/db_password,target=/run/secrets/db_password,readonly" \
  "${mounts[@]}" "$image" sh -c 'php apps/api/bin/console --no-debug --quiet cache:clear --no-warmup && exec php apps/api/bin/console --no-debug app:import:workbook "$@"' import "${arguments[@]}"
