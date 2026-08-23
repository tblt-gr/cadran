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

mkdir -p var/docker-secrets
chmod 700 var/docker-secrets

generate_secret() {
    destination=$1

    if [ -s "$destination" ]; then
        return
    fi

    umask 077
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$destination"
    printf '\n' >> "$destination"
}

generate_secret var/docker-secrets/app_secret
generate_secret var/docker-secrets/db_password

chmod 444 var/docker-secrets/app_secret var/docker-secrets/db_password
