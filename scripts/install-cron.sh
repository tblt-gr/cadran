#!/bin/sh
# Installs, previews or removes the cadran block of the current user's crontab.
# Only the lines between "# BEGIN cadran" and "# END cadran" are ever touched.
#   sh scripts/install-cron.sh [--dry-run | --remove]
set -eu

usage() {
    printf 'usage: install-cron.sh [--dry-run | --remove]\n' >&2
    exit 2
}

[ "$#" -le 1 ] || usage
mode=install
case ${1:-} in
    '') ;;
    --dry-run) mode=dry ;;
    --remove) mode=remove ;;
    *) usage ;;
esac

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
newline='
'
# The root is placed in single quotes inside a cron line, where % is special.
case $root in
    *"$newline"* | *%* | *\'*)
        printf 'install-cron: the repository path must not contain a newline, %% or a single quote\n' >&2
        exit 1
        ;;
esac

block() {
    printf '%s\n' '# BEGIN cadran (managed by scripts/install-cron.sh)'
    printf "5 0 * * * cd '%s' && make recurrences-materialize >/dev/null 2>&1\n" "$root"
    printf "0 3 * * * cd '%s' && make db-backup LABEL=daily >/dev/null 2>&1\n" "$root"
    printf "30 3 * * * cd '%s' && make db-backup-prune >/dev/null 2>&1\n" "$root"
    printf "0 4 * * 0 cd '%s' && make idempotency-purge >/dev/null 2>&1\n" "$root"
    printf '%s\n' '# END cadran'
}

if [ "$mode" = dry ]; then
    block
    exit 0
fi

# Everything outside the managed block stays byte for byte.
others=$( (crontab -l 2>/dev/null || true) | awk '
    /^# BEGIN cadran/ { skip = 1; next }
    /^# END cadran/ { skip = 0; next }
    !skip { print }
')

{
    [ -z "$others" ] || printf '%s\n' "$others"
    [ "$mode" = remove ] || block
} | crontab -
