#!/bin/sh
# make doctor: one line per check, exit 1 when the installation needs attention.
# Prints no secret, backup content or financial value.
set -u

cd -- "$(dirname -- "$0")/.." || exit 1
env_file=.env.example
[ -f .env.local ] && env_file=.env.local
compose() { docker compose --env-file "$env_file" "$@"; }

failed=0
ok() { printf 'OK %s\n' "$1"; }
warn() { printf 'WARN %s: %s\n' "$1" "$2"; }
fail() {
    printf 'FAIL %s: %s\n' "$1" "$2"
    failed=1
}

if compose exec -T app php /usr/local/bin/healthcheck.php > /dev/null 2>&1; then
    ok 'app health'
else
    fail 'app health' 'the application is unhealthy or not running'
fi

# `exec` skips the image entrypoint that exports the runtime options and DATABASE_URL, so the console
# only works through a one-off container that goes through the entrypoint.
if compose run --rm --no-deps app php apps/api/bin/console doctrine:migrations:up-to-date --no-interaction > /dev/null 2>&1; then
    ok migrations
else
    fail migrations 'a migration is pending or the check could not run'
fi

if backup_report=$(sh scripts/backup.sh health --strict 2>&1); then
    ok backup
else
    reason=$(printf '%s\n' "$backup_report" | sed -n 's/^Backup: WARNING //p;s/^backup: //p' | head -n 1)
    fail backup "${reason:-the backup check failed}"
fi

if crontab -l 2> /dev/null | grep -q '^# BEGIN cadran'; then
    ok cron
else
    warn cron 'no cadran block in the crontab; run sh scripts/install-cron.sh'
fi

if size=$(compose exec -T db psql --username=cadran --dbname=cadran --tuples-only --no-align \
    --command='SELECT pg_size_pretty(pg_database_size(current_database()))' 2> /dev/null) && [ -n "$size" ]; then
    printf 'OK database size: %s\n' "$size"
else
    fail 'database size' 'could not query the database'
fi

exit "$failed"
