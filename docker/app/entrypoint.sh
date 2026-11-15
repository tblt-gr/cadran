#!/bin/sh

set -eu

if [ -z "${APP_RUNTIME_OPTIONS:-}" ]; then
    export APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
fi

read_secret() {
    secret_path=$1

    if [ ! -r "$secret_path" ]; then
        printf 'Required Docker secret is unavailable: %s\n' "$secret_path" >&2
        exit 1
    fi

    tr -d '\r\n' < "$secret_path"
}

case ${LOG_LEVEL:-notice} in
    debug | info | notice | warning | error) ;;
    *)
        printf 'LOG_LEVEL must be one of: debug, info, notice, warning, error.\n' >&2
        exit 1
        ;;
esac

app_secret=$(read_secret /run/secrets/app_secret)
db_password=$(read_secret /run/secrets/db_password)

export APP_SECRET="$app_secret"
export DATABASE_URL="postgresql://cadran:${db_password}@db:5432/cadran?serverVersion=18&charset=utf8"

unset app_secret db_password

exec docker-php-entrypoint "$@"
