#!/bin/sh
# Rehearses a cold restore on a disposable stack (OPS-012): own compose project, own secrets, own
# port, so the personal installation is never read, stopped or written. Subcommands:
#   run       build and start the drill database, restore FILE with the offline IDENTITY,
#             migrate, start the app and print the doctor result of the drill stack
#   cleanup   after an interactive yes, remove the drill containers, network, volumes and files
#
# Environment: FILE and IDENTITY (run). CADRAN_DRILL_STACK (default cadran_drill) and
# CADRAN_DRILL_PORT (default 8445) exist for tests; the stack `cadran` and port 8443 are refused.
# CADRAN_BACKUP_ALLOW_TEST_STDIN=1 lets cleanup read its yes from a pipe (tests only).
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
cd "$repo_root"

# An exported personal-stack setting is a sign the command is aimed at the wrong installation.
[ "${CADRAN_STACK:-cadran_drill}" != cadran ] || {
    printf 'restore-drill: refusing to run with CADRAN_STACK=cadran\n' >&2
    exit 1
}
[ "${CADRAN_HTTPS_PORT:-8445}" != 8443 ] || {
    printf 'restore-drill: refusing to run with CADRAN_HTTPS_PORT=8443\n' >&2
    exit 1
}
stack=${CADRAN_DRILL_STACK:-cadran_drill}
port=${CADRAN_DRILL_PORT:-8445}
secrets_dir=var/drill-secrets
backups_dir=var/drill-backups

die() {
    printf 'restore-drill: %s\n' "$1" >&2
    exit 1
}

case $stack in
    cadran) die 'refusing to target the personal stack cadran' ;;
    cadran_?*) ;;
    *) die 'the drill stack must be named cadran_<suffix>' ;;
esac
case ${stack#cadran_} in
    *[!a-z0-9]*) die 'the drill stack must be named cadran_<suffix>' ;;
esac
case $port in
    8443) die 'refusing to use port 8443, the port of the personal stack' ;;
    '' | *[!0-9]*) die 'the drill port must be a number' ;;
esac

# Every command below is scoped to the drill project by name, secrets and port.
drill_compose() {
    env "CADRAN_STACK=$stack" "CADRAN_SECRETS_DIR=./$secrets_dir" "CADRAN_HTTPS_PORT=$port" \
        docker compose --project-name "$stack" --env-file .env.example "$@"
}

run_drill() {
    [ -n "${FILE:-}" ] || die 'FILE is required: the path of a cadran-*.dump.age backup'
    [ -n "${IDENTITY:-}" ] || die 'IDENTITY is required: the offline age identity file'

    (umask 077 && mkdir -p "$secrets_dir" "$backups_dir")
    chmod 700 "$secrets_dir" "$backups_dir"
    CADRAN_SECRETS_DIR=$secrets_dir sh scripts/init-local.sh

    drill_env() {
        env "CADRAN_STACK=$stack" "CADRAN_SECRETS_DIR=./$secrets_dir" \
            "CADRAN_BACKUP_DIR=$repo_root/$backups_dir" CONFIRM=restore-cadran "$@"
    }
    # The drill reads only the offline identity: the public recipient is derived from it.
    drill_env sh scripts/backup.sh restore-precheck
    case $IDENTITY in
        /*) identity_path=$IDENTITY ;;
        *) identity_path=$PWD/$IDENTITY ;;
    esac
    recipient=$(docker run --rm --network none --read-only --cap-drop ALL \
        --security-opt no-new-privileges --user "$(id -u):$(id -g)" \
        --mount "type=bind,source=$identity_path,target=/run/backup_identity,readonly" \
        --entrypoint age-keygen cadran_backup:local -y /run/backup_identity) ||
        die 'could not derive the public recipient from IDENTITY'
    case $recipient in
        age1*) ;;
        *) die 'could not derive the public recipient from IDENTITY' ;;
    esac
    (umask 077 && printf '%s\n' "$recipient" > "$secrets_dir/backup_recipient")
    chmod 600 "$secrets_dir/backup_recipient"

    drill_compose build app db
    drill_compose up --detach --wait db
    # The app is not running yet, so the restore has no service to stop or restart.
    drill_env CADRAN_RESTORE_SKIP_APP=1 sh scripts/backup.sh restore
    drill_compose run --rm --no-deps app php apps/api/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    drill_compose up --detach --wait app
    printf 'Drill stack %s is up on https://localhost:%s\n' "$stack" "$port"
    doctor_status=0
    env "CADRAN_STACK=$stack" "CADRAN_SECRETS_DIR=./$secrets_dir" "CADRAN_BACKUP_DIR=$repo_root/$backups_dir" \
        sh scripts/doctor.sh || doctor_status=$?
    printf 'Remove the drill with make restore-drill-cleanup.\n'
    return "$doctor_status"
}

cleanup_drill() {
    printf 'This removes the containers, network and volumes of %s and deletes %s and %s.\n' \
        "$stack" "$secrets_dir" "$backups_dir"
    if [ ! -t 0 ] && [ "${CADRAN_BACKUP_ALLOW_TEST_STDIN:-}" != 1 ]; then
        die 'an interactive terminal is required to confirm the removal'
    fi
    printf 'Type yes to confirm: '
    answer=''
    IFS= read -r answer || true
    if [ "$answer" != yes ]; then
        printf 'Nothing removed.\n'
        return 0
    fi
    docker rm --force "${stack}_backup_run" > /dev/null 2>&1 || true
    drill_compose down --volumes --remove-orphans
    rm -rf -- "$secrets_dir" "$backups_dir"
    printf 'Drill stack %s removed.\n' "$stack"
}

case ${1:-} in
    run) run_drill ;;
    cleanup) cleanup_drill ;;
    *) die 'usage: restore-drill.sh run | cleanup' ;;
esac
