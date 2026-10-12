#!/bin/sh
# Host side of the encrypted database backups (OPS-001): validates paths, then
# runs the one-shot cadran_backup container. Subcommands:
#   key       create an age key pair (identity stays outside the repository)
#   create    take an encrypted backup (LABEL: manual, daily, pre-upgrade, pre-restore)
#   prune     apply the retention policy (--dry-run to preview)
#   health    print the age of the newest backup; warnings never fail
#   verify    restore FILE into the isolated cadran_restore_check database and check it
#   restore   verify FILE, take a pre-restore backup, replace the live database
#   restore-precheck   validate CONFIRM, FILE and IDENTITY without any docker call
#   restore-cleanup    list and (after an interactive yes) drop cadran_previous_*
#
# Environment: CADRAN_BACKUP_DIR (also read from .env.local), IDENTITY,
# LABEL, CADRAN_BACKUP_RECIPIENT_FILE, CADRAN_DB_PASSWORD_FILE, CADRAN_DB_NAME,
# FILE and CONFIRM (restore), CADRAN_BACKUP_NOW with
# CADRAN_BACKUP_ALLOW_TEST_CLOCK=1 (retention tests only).
# One restore at a time is supported: the containers share one name
# (cadran_backup_run) and the isolated databases have fixed names.
# Test-only switches, never needed in normal use:
#   CADRAN_RESTORE_SKIP_APP=1  do not stop or restart the app service during a
#                              restore (the CI check restores a throwaway
#                              database the application is not wired to).
#   CADRAN_BACKUP_ALLOW_TEST_STDIN=1  let restore-cleanup read its yes from a pipe.
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

# --- Restore (OPS-002) --------------------------------------------------------

confirm_value=restore-cadran
backup_label_pattern='^cadran-[0-9]{8}-[0-9]{6}-(manual|daily|pre-upgrade|pre-restore)\.dump\.age$'
newline='
'

reject_separators() {
    # reject_separators <name> <value>: the value ends up in a mount specification
    case $2 in
        *,* | *'"'* | *"$newline"*) die "$1 must not contain a comma, a double quote or a line break" ;;
    esac
}

require_confirm() {
    [ "${CONFIRM:-}" = "$confirm_value" ] ||
        die "refusing to replace the live database: pass CONFIRM=$confirm_value to confirm"
}

require_regular_file() {
    # require_regular_file <message> <path>
    { [ -f "$2" ] && [ ! -L "$2" ]; } || die "$1"
}

# Validates FILE and IDENTITY before anything is mounted or decrypted.
# Sets restore_dump, restore_sum, restore_manifest, restore_identity and latest_schema.
resolve_restore_inputs() {
    file=${FILE:-}
    [ -n "$file" ] || die 'FILE is required: the path of a cadran-*.dump.age backup'
    reject_separators FILE "$file"
    case $file in
        /*) ;;
        *) file=$PWD/$file ;;
    esac
    [ ! -L "$file" ] || die 'FILE must be a regular file, not a symbolic link'
    [ -f "$file" ] || die 'FILE must be a regular file'
    file_parent=$(CDPATH= cd -P -- "$(dirname -- "$file")" && pwd -P) || die 'FILE directory is not readable'
    file_base=$(basename -- "$file")
    reject_separators FILE "$file_parent/$file_base"
    printf '%s\n' "$file_base" | grep -Eq "$backup_label_pattern" ||
        die 'FILE name must look like cadran-YYYYMMDD-HHMMSS-<label>.dump.age'
    restore_dump=$file_parent/$file_base
    restore_sum=$restore_dump.sha256
    restore_manifest=$file_parent/${file_base%.dump.age}.manifest.json
    require_regular_file 'the checksum file next to FILE is missing or is not a regular file' "$restore_sum"
    require_regular_file 'the manifest next to FILE is missing or is not a regular file' "$restore_manifest"

    identity=${IDENTITY:-}
    [ -n "$identity" ] || die 'IDENTITY is required: the offline age identity file'
    reject_separators IDENTITY "$identity"
    case $identity in
        /*) ;;
        *) identity=$PWD/$identity ;;
    esac
    case $identity in
        */../* | */.. | */./* | */.) die 'IDENTITY must not contain . or .. components' ;;
    esac
    [ ! -L "$identity" ] || die 'IDENTITY must be a regular file, not a symbolic link'
    [ -f "$identity" ] || die 'IDENTITY must be a regular file'
    restore_identity=$identity

    database=${CADRAN_DB_NAME:-cadran}
    printf '%s\n' "$database" | grep -Eq '^[a-z0-9_]{1,63}$' || die 'invalid CADRAN_DB_NAME'
    [ -s "$password_file" ] || die 'the database password file is missing; run make init first'
    latest_migration=$(find "$repo_root/apps/api/migrations" -maxdepth 1 -type f -name 'Version*.php' |
        sed -n 's|^.*/\(Version[0-9]\{14\}\)\.php$|\1|p' | LC_ALL=C sort | tail -n 1)
    [ -n "$latest_migration" ] || die 'could not find the latest migration of the running code'
    latest_schema="DoctrineMigrations\\$latest_migration"
}

# Database administration container: password only, no backup material.
admin_container() {
    run_container --network cadran_internal --env "CADRAN_DB_NAME=${CADRAN_DB_NAME:-cadran}" \
        --mount "type=bind,source=$password_file,target=/run/db_password,readonly" \
        "$image" "$@"
}

# Same plus the three backup files and the identity, mounted one by one, read-only.
restore_container() {
    run_container --network cadran_internal \
        --env "CADRAN_DB_NAME=${CADRAN_DB_NAME:-cadran}" --env "CADRAN_LATEST_SCHEMA=$latest_schema" \
        --mount "type=bind,source=$password_file,target=/run/db_password,readonly" \
        --mount "type=bind,source=$restore_dump,target=/restore/backup.dump.age,readonly" \
        --mount "type=bind,source=$restore_sum,target=/restore/backup.dump.age.sha256,readonly" \
        --mount "type=bind,source=$restore_manifest,target=/restore/backup.manifest.json,readonly" \
        --mount "type=bind,source=$restore_identity,target=/run/backup_identity,readonly" \
        "$image" "$@"
}

compose() {
    compose_env=$repo_root/.env.example
    if [ -f "$repo_root/.env.local" ]; then
        compose_env=$repo_root/.env.local
    fi
    docker compose --project-directory "$repo_root" --env-file "$compose_env" "$@"
}

restore_precheck() {
    require_confirm
    resolve_restore_inputs
}

verify() {
    resolve_restore_inputs
    restore_container verify
}

restore_exit() {
    restore_status=$?
    trap - EXIT INT TERM HUP
    if [ "$restore_status" -ne 0 ]; then
        docker rm --force cadran_backup_run > /dev/null 2>&1 || true
    fi
    if [ "$swapped" -eq 0 ] && [ "$fresh_started" -eq 1 ]; then
        admin_container drop-new > /dev/null 2>&1 || true
    fi
    if [ "$app_stopped" -eq 1 ]; then
        if compose up --detach --wait app > /dev/null 2>&1; then
            printf 'restore: the app service was restarted on the live database.\n' >&2
        else
            printf 'restore: could not restart the app service; run make up.\n' >&2
        fi
    fi
    exit "$restore_status"
}

restore() {
    restore_precheck
    resolve_backup_dir
    [ -s "$recipient_file" ] || die 'no backup recipient; run make backup-key first'

    printf 'Step 1/4: verifying the backup in an isolated database\n'
    restore_container verify || die 'verification failed; the live database was not touched'

    printf 'Step 2/4: taking a pre-restore backup of the live database\n'
    (
        LABEL=pre-restore
        export LABEL
        create
    ) || die 'the pre-restore backup failed; the live database was not touched'

    swapped=0
    fresh_started=0
    app_stopped=0
    trap restore_exit EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    trap 'exit 129' HUP

    printf 'Step 3/4: restoring into a new database\n'
    if [ "${CADRAN_RESTORE_SKIP_APP:-}" != 1 ]; then
        compose stop app > /dev/null || die 'could not stop the app service; the live database was not touched'
        app_stopped=1
    fi
    fresh_started=1
    restore_container restore-new || die 'the restore failed; the live database was not touched'

    printf 'Step 4/4: replacing the live database\n'
    swap_output=$(admin_container swap) || die 'the replacement failed; the live database was not touched'
    swapped=1
    previous=$(printf '%s\n' "$swap_output" | sed -n 's/^previous=\(cadran_previous_[0-9]\{14\}\)$/\1/p' | head -n 1)
    failed=$(printf '%s\n' "$swap_output" | sed -n 's/^failed=\(cadran_failed_[0-9]\{14\}\)$/\1/p' | head -n 1)
    [ -n "$previous" ] && [ -n "$failed" ] || die 'unexpected replacement result; check the databases with make db-restore-cleanup'

    if [ "${CADRAN_RESTORE_SKIP_APP:-}" = 1 ]; then
        printf 'The app service was not touched (CADRAN_RESTORE_SKIP_APP=1).\n'
    elif compose up --detach --wait app > /dev/null 2>&1 &&
        compose exec -T app php /usr/local/bin/healthcheck.php > /dev/null 2>&1; then
        app_stopped=0
    else
        printf 'The application is unhealthy on the restored database: rolling back.\n' >&2
        if admin_container rollback "$previous" "$failed"; then
            swapped=0
            fresh_started=0
            compose stop app > /dev/null 2>&1 || true
            if compose up --detach --wait app > /dev/null 2>&1; then
                app_stopped=0
                die "rolled back: the previous database is live again; the failed one is kept as $failed"
            fi
            die "rolled back to the previous database, but the app did not restart; run make up. Failed database: $failed"
        fi
        app_stopped=0
        die "the rollback failed; the live database is missing: rename $previous to the live name by hand"
    fi
    printf 'Restore complete. The replaced database is kept as %s.\n' "$previous"
    printf 'Run make db-restore-cleanup once the restored data is confirmed.\n'
}

restore_cleanup() {
    [ -s "$password_file" ] || die 'the database password file is missing; run make init first'
    names=$(admin_container list-previous) || die 'could not list the previous databases'
    names=$(printf '%s\n' "$names" | grep -E '^cadran_previous_[0-9]{14}$' || true)
    if [ -z "$names" ]; then
        printf 'No cadran_previous_* database to clean up.\n'
        return 0
    fi
    printf 'Databases kept after a restore:\n'
    printf '  %s\n' $names
    if [ ! -t 0 ] && [ "${CADRAN_BACKUP_ALLOW_TEST_STDIN:-}" != 1 ]; then
        die 'an interactive terminal is required to confirm the drop'
    fi
    printf 'Drop all of them permanently? Type yes to confirm: '
    answer=''
    IFS= read -r answer || true
    if [ "$answer" != yes ]; then
        printf 'Nothing dropped.\n'
        return 0
    fi
    admin_container drop-previous
}

command=${1:-}
[ "$#" -eq 0 ] || shift
case $command in
    key) key ;;
    create) create ;;
    prune) prune "$@" ;;
    health) health ;;
    verify) verify ;;
    restore) restore ;;
    restore-precheck) restore_precheck ;;
    restore-cleanup) restore_cleanup ;;
    *) die 'usage: backup.sh key | create | prune [--dry-run] | health | verify | restore | restore-precheck | restore-cleanup' ;;
esac
