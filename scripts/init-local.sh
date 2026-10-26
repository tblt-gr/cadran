#!/bin/sh

set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$repo_root"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    printf 'Docker with the Compose plugin is required.\n' >&2
    exit 1
fi

if [ ! -f .env.local ]; then
    install -m 600 .env.example .env.local
fi

secrets_dir=${CADRAN_SECRETS_DIR:-var/docker-secrets}

mkdir -p "$secrets_dir"
chmod 700 "$secrets_dir"

generate_secret() {
    destination=$1

    if [ -s "$destination" ]; then
        return
    fi

    umask 077
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$destination"
    printf '\n' >> "$destination"
}

generate_secret "$secrets_dir/app_secret"
generate_secret "$secrets_dir/db_password"

chmod 444 "$secrets_dir/app_secret" "$secrets_dir/db_password"
