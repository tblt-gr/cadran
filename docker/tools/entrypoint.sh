#!/bin/sh

set -eu

if [ -z "${APP_RUNTIME_OPTIONS:-}" ]; then
    export APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
fi

if [ -r /run/secrets/db_password ]; then
    db_password=$(tr -d '\r\n' < /run/secrets/db_password)
    export DATABASE_URL="postgresql://cadran:${db_password}@db:5432/cadran?serverVersion=18&charset=utf8"
    unset db_password
fi

exec "$@"
