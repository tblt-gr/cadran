#!/bin/sh

set -eu

for required_file in .env.local var/docker-secrets/app_secret var/docker-secrets/db_password; do
    if [ ! -s "$required_file" ]; then
        printf 'Missing local runtime file %s; run make init first.\n' "$required_file" >&2
        exit 1
    fi
done
