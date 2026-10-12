#!/bin/sh
# Host side of the encrypted database backups (OPS-001): validates paths, then
# runs the one-shot cadran_backup container. Subcommands:
#   key       create an age key pair (identity stays outside the repository)
#   create    take an encrypted backup (LABEL: manual, daily, pre-upgrade, pre-restore)
#   prune     apply the retention policy (--dry-run to preview)
#   health    print the age of the newest backup; warnings never fail
#
# Environment: CADRAN_BACKUP_DIR (also read from .env.local), IDENTITY,
# LABEL, CADRAN_BACKUP_RECIPIENT_FILE, CADRAN_DB_PASSWORD_FILE, CADRAN_DB_NAME,
# CADRAN_BACKUP_NOW with CADRAN_BACKUP_ALLOW_TEST_CLOCK=1 (retention tests only).
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
image=cadran_backup:local
recipient_file=${CADRAN_BACKUP_RECIPIENT_FILE:-$repo_root/var/docker-secrets/backup_recipient}
password_file=${CADRAN_DB_PASSWORD_FILE:-$repo_root/var/docker-secrets/db_password}

die() {
    printf 'backup: %s\n' "$1" >&2
    exit 1
}

read_backup_dir_setting() {
    if [ -n "${CADRAN_BACKUP_DIR:-}" ]; then
        printf '%s' "$CADRAN_BACKUP_DIR"
        return
    fi
    if [ -f "$repo_root/.env.local" ]; then
        setting=$(sed -n 's/^CADRAN_BACKUP_DIR=//p' "$repo_root/.env.local" | tail -n 1 | tr -d '"'"'"'\r')
        if [ -n "$setting" ]; then
            printf '%s' "$setting"
            return
        fi
    fi
    printf '%s' "$repo_root/backups"
}

# Sets backup_dir to the physical path, or dies when it is unsafe.
resolve_backup_dir() {
    candidate=$(read_backup_dir_setting)
    if [ "$candidate" = "$repo_root/backups" ] && [ -z "${CADRAN_BACKUP_DIR:-}" ] && ! grep -q '^CADRAN_BACKUP_DIR=' "$repo_root/.env.local" 2>/dev/null; then
        # Only the untouched default is created on demand; a configured directory must exist.
        mkdir -p -m 700 -- "$candidate"
    fi
    case $candidate in
        /*) ;;
        *) candidate=$PWD/$candidate ;;
    esac
    while [ "${candidate%/}" != "$candidate" ] && [ "$candidate" != / ]; do
        candidate=${candidate%/}
    done
    case $candidate in
        *,* | *'
'*) die 'the backup directory path must not contain a comma or a line break' ;;
    esac
    [ -d "$candidate" ] || die 'the backup directory does not exist'
    [ ! -L "$candidate" ] || die 'the backup directory must not be a symbolic link'
    backup_dir=$(CDPATH= cd -P -- "$candidate" && pwd -P)
    [ "$backup_dir" != / ] || die 'the backup directory must not be the filesystem root'
    [ "$backup_dir" != "$repo_root" ] || die 'the backup directory must not be the repository root'
}

run_container() {
    # Reads no secret from the environment or the command line; mounts come from the caller.
    docker run --rm --init --name cadran_backup_run --read-only --tmpfs /tmp \
        --cap-drop ALL --security-opt no-new-privileges \
        --user "$(id -u):$(id -g)" "$@"
}

key() {
    identity=${IDENTITY:-$HOME/.config/cadran/backup-identity.txt}
    case $identity in
        '~/'*) identity=$HOME/${identity#'~/'} ;;
    esac
    case $identity in
        /*) ;;
        *) identity=$PWD/$identity ;;
    esac
    case $identity in
        */../* | */.. | */./* | */.) die 'the identity path must not contain . or .. components' ;;
    esac
    resolve_backup_dir
    [ ! -e "$identity" ] && [ ! -L "$identity" ] || die 'the identity file already exists; it is never overwritten'
    [ ! -e "$recipient_file" ] && [ ! -L "$recipient_file" ] ||
        die 'a backup recipient already exists; remove it deliberately to rotate keys (older backups need the old identity)'

    forbid_identity_inside "$(dirname -- "$identity")"
    (umask 077 && mkdir -p -- "$(dirname -- "$identity")")
    forbid_identity_inside "$(CDPATH= cd -P -- "$(dirname -- "$identity")" && pwd -P)"

    if ! (umask 077 && set -C && run_container --network none "$image" keygen > "$identity" 2>/dev/null); then
        rm -f -- "$identity"
        die 'key generation failed'
    fi
    chmod 600 "$identity"
    recipient=$(sed -n 's/^# public key: //p' "$identity" | head -n 1)
    case $recipient in
        age1*) ;;
        *)
            rm -f -- "$identity"
            die 'could not read the public recipient'
            ;;
    esac
    (umask 077 && printf '%s\n' "$recipient" > "$recipient_file")
    chmod 600 "$recipient_file"
    printf 'Public recipient: %s\n' "$recipient"
    printf 'Store the identity file (%s) offline, away from this machine and from the backups: without it no backup can be read.\n' "$identity"
}

forbid_identity_inside() {
    for forbidden in "$repo_root" "$backup_dir"; do
        case "$1/" in
            "$forbidden"/*) die 'the identity must not live inside the repository or the backup directory' ;;
        esac
    done
}

create() {
    label=${LABEL:-manual}
    case $label in
        manual | daily | pre-upgrade | pre-restore) ;;
        *) die 'LABEL must be one of manual, daily, pre-upgrade, pre-restore' ;;
    esac
    [ -s "$recipient_file" ] || die 'no backup recipient; run make backup-key first'
    [ -s "$password_file" ] || die 'the database password file is missing; run make init first'
    resolve_backup_dir
    app_version=$(sed -n 's/^  "version": "\([0-9A-Za-z.+-]*\)",$/\1/p' "$repo_root/package.json" | head -n 1)
    run_container --network cadran_internal \
        --env "CADRAN_APP_VERSION=${app_version:-unknown}" --env "CADRAN_DB_NAME=${CADRAN_DB_NAME:-cadran}" \
        --mount "type=bind,source=$password_file,target=/run/db_password,readonly" \
        --mount "type=bind,source=$recipient_file,target=/run/backup_recipient,readonly" \
        --mount "type=bind,source=$backup_dir,target=/backups" \
        "$image" create "$label"
}

prune() {
    resolve_backup_dir
    run_container --network none \
        --env "CADRAN_BACKUP_NOW=${CADRAN_BACKUP_NOW:-}" \
        --env "CADRAN_BACKUP_ALLOW_TEST_CLOCK=${CADRAN_BACKUP_ALLOW_TEST_CLOCK:-}" \
        --mount "type=bind,source=$backup_dir,target=/backups" \
        "$image" prune "$@"
}

health() {
    if ! (resolve_backup_dir) 2>/dev/null; then
        printf 'Backup: WARNING the backup directory is missing or unsafe.\n'
        return 0
    fi
    resolve_backup_dir
    # A manifest without its dump (failed or interrupted run) must not hide a missing backup.
    newest=$(find "$backup_dir" -maxdepth 1 -type f -name 'cadran-*.manifest.json' | sort -r |
        while IFS= read -r manifest; do
            dump=${manifest%.manifest.json}.dump.age
            if [ -f "$dump" ] && [ ! -L "$dump" ]; then
                printf '%s\n' "$manifest"
                break
            fi
        done)
    if [ -z "$newest" ]; then
        printf 'Backup: WARNING no backup found.\n'
        return 0
    fi
    created=$(sed -n 's/^  "createdAt": "\(.*\)",$/\1/p' "$newest")
    label=$(sed -n 's/^  "label": "\(.*\)",$/\1/p' "$newest")
    created_epoch=$(date -u -d "$created" +%s 2>/dev/null || true)
    if [ -z "$created_epoch" ]; then
        printf 'Backup: WARNING newest manifest is unreadable.\n'
        return 0
    fi
    age_hours=$((($(date -u +%s) - created_epoch) / 3600))
    printf 'Backup: newest is %s hours old (%s).\n' "$age_hours" "$label"
    if [ "$age_hours" -gt 48 ]; then
        printf 'Backup: WARNING newest backup is older than 48 hours.\n'
    fi
}

command=${1:-}
[ "$#" -eq 0 ] || shift
case $command in
    key) key ;;
    create) create ;;
    prune) prune "$@" ;;
    health) health ;;
    *) die 'usage: backup.sh key | create | prune [--dry-run] | health' ;;
esac
