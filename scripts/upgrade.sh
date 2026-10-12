#!/bin/sh
# Safe upgrade and rollback of an installation (OPS-003). Called by make:
#   upgrade   make upgrade VERSION=vX.Y.Z IDENTITY=<age identity> [FROM=vX.Y.Z]
#   rollback  make rollback IDENTITY=<age identity>
#   precheck  the refusals of upgrade only; no change, no Docker beyond the
#             read-only query of the running version
#
# Upgrade, each step stopping on failure with the exact rollback command:
#   1. refuse: malformed VERSION, missing IDENTITY (the offline age identity is
#      mandatory: the new backup is restore-verified with it), uncommitted
#      changes, unknown tag, tag that differs between this clone and origin, tag
#      not an ancestor of origin/main, a tag whose package.json declares another
#      version, HEAD that is not exactly a release tag (unless FROM=vX.Y.Z names
#      the release actually running, for example the first upgrade from v0.7.0
#      after git pull on main), VERSION not newer than the running version
#      (numeric X.Y.Z; the running version is the one reported by the running
#      container when the stack is up and not "unknown", else package.json, and
#      FROM when it is given);
#   2. make db-backup LABEL=pre-upgrade, check the three backup files, then
#      make db-backup-verify (restore into an isolated database): no later step
#      runs without a verified backup;
#   3. write var/upgrade/last-upgrade.json (mode 0600, directory 0700);
#   4. check out the verified commit, build, stop the app, migrate, start,
#      show the status. The app is stopped before the migration so the previous
#      image never runs on a newer schema. An interruption (INT, TERM, HUP)
#      after step 3 prints the rollback command like any failure;
#   5. record finishedAt.
# The backup is taken while the application still serves requests: whatever is
# written between the backup and the stop of the app in step 4 is not in the
# pre-upgrade backup. A rollback keeps it: the restore first takes a pre-restore
# backup of the live database and the replaced database stays as
# cadran_previous_* until make db-restore-cleanup.
# One upgrade or rollback at a time: var/upgrade/lock (a directory) is the lock;
# remove it by hand only after a killed run.
#
# Rollback order differs from "check out, then restore" on purpose. Releases
# older than v1.0.0 have no backup tooling and db-restore restarts the app image,
# so the restore runs first, with the CURRENT tree's tooling, while the app stays
# stopped (CADRAN_RESTORE_LEAVE_APP_STOPPED=1 in scripts/backup.sh). Only then is
# the previous commit checked out, built and started. Each image therefore only
# ever runs on its own schema. The restore itself takes a pre-restore backup of
# the live database, so nothing is lost by rolling back.
#
# Checkouts are by commit hash resolved and verified beforehand, never by the
# mutable tag name. Tags are fetched without --force: a local tag that differs
# from origin is refused, never moved. A rollback leaves a detached HEAD; return
# to a branch with git switch main.
#
# Nothing here prints a password, the identity file content or backup content.
# Everything runs inside functions and the last line is a single statement:
# git checkout rewrites this file while it is running.
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
state_dir=$repo_root/var/upgrade
state_file=$state_dir/last-upgrade.json
newline='
'
version_pattern='^v[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$'
commit_pattern='^[0-9a-f]{40,64}$'
backup_name_pattern='^cadran-[0-9]{8}-[0-9]{6}-pre-upgrade\.dump\.age$'
timestamp_pattern='^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$'

die() {
    printf 'upgrade: %s\n' "$1" >&2
    exit 1
}

git() {
    command git -C "$repo_root" "$@"
}

lock_held=0

release_lock() {
    if [ "$lock_held" -eq 1 ]; then
        rmdir "$state_dir/lock" 2> /dev/null || true
        lock_held=0
    fi
}

acquire_lock() {
    (
        umask 077
        mkdir -p "$state_dir"
        chmod 700 "$state_dir"
    )
    mkdir "$state_dir/lock" 2> /dev/null ||
        die 'another upgrade or rollback is already running (after a killed run, remove var/upgrade/lock)'
    lock_held=1
    trap release_lock EXIT
}

now() {
    date -u +%Y-%m-%dT%H:%M:%SZ
}

compose() {
    compose_env=.env.example
    if [ -f "$repo_root/.env.local" ]; then
        compose_env=.env.local
    fi
    (cd "$repo_root" && docker compose --env-file "$compose_env" "$@")
}

# The image of the checked-out tree must carry that tree's version, not the one
# inherited from the make that started this script.
export_version() {
    CADRAN_VERSION=$(sed -n 's/^  "version": "\([0-9][0-9.]*\)".*/\1/p' "$repo_root/package.json" | head -n 1)
    export CADRAN_VERSION
}

shell_quote() {
    case $1 in
        *[!A-Za-z0-9_./~:@%+=-]* | '') printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")" ;;
        *) printf '%s' "$1" ;;
    esac
}

rollback_command() {
    if [ -n "${IDENTITY:-}" ]; then
        printf 'make rollback IDENTITY=%s' "$(shell_quote "$IDENTITY")"
    else
        printf 'make rollback IDENTITY=<path of your offline identity>'
    fi
}

# version_newer <a> <b>: both X.Y.Z, true when a is strictly greater, numerically.
version_newer() {
    awk -v a="$1" -v b="$2" 'BEGIN {
        split(a, x, "."); split(b, y, ".")
        for (i = 1; i <= 3; i++) {
            if (x[i] + 0 > y[i] + 0) exit 0
            if (x[i] + 0 < y[i] + 0) exit 1
        }
        exit 1
    }'
}

semver_pattern='^[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$'

# Version declared by a package.json read from stdin; empty when unreadable.
declared_version() {
    sed -n 's/^  "version": "\([0-9A-Za-z.+-]*\)",$/\1/p' | head -n 1
}

# Version reported by the running app container, empty when the stack is down
# or the image predates the version (unknown). Read-only.
container_version() {
    reported=$(compose exec -T app printenv CADRAN_VERSION 2> /dev/null | tr -d '\r\n') || reported=
    if printf '%s\n' "$reported" | grep -Eq "$semver_pattern"; then
        printf '%s' "$reported"
    fi
}

require_clean_tree() {
    dirty=$(git status --porcelain) || die 'could not read the git status'
    [ -z "$dirty" ] || die 'the working tree has uncommitted changes: commit or stash them first'
}

# --- Upgrade refusals ----------------------------------------------------------

# Sets target_commit, from, from_commit and running.
precheck() {
    version=${VERSION:-}
    case $version in
        *"$newline"*) die 'VERSION must look like vX.Y.Z, for example v1.0.0' ;;
    esac
    printf '%s\n' "$version" | grep -Eq "$version_pattern" ||
        die 'VERSION must look like vX.Y.Z, for example v1.0.0'
    { [ -n "${IDENTITY:-}" ] && [ -f "$IDENTITY" ] && [ ! -L "$IDENTITY" ]; } ||
        die 'IDENTITY is required: the offline age identity file (IDENTITY=/path/to/identity.txt); the new backup is restore-verified with it'
    require_clean_tree

    fetch_output=$(git fetch --tags origin 2>&1) || {
        printf '%s\n' "$fetch_output" >&2
        die 'could not fetch tags and branches from origin (a local tag that differs from the one on origin is refused, never overwritten)'
    }

    # An annotated tag lists its commit on the peeled "^{}" line.
    remote_commit=$(git ls-remote --tags origin "refs/tags/$version" "refs/tags/$version^{}" |
        awk -v tag="refs/tags/$version" '$2 == tag "^{}" { peeled = $1 } $2 == tag { direct = $1 } END { print peeled != "" ? peeled : direct }') ||
        die 'could not list the tags of origin'
    [ -n "$remote_commit" ] || die "the tag $version does not exist on origin"
    local_commit=$(git rev-parse --verify --quiet "refs/tags/$version^{commit}") ||
        die "the tag $version does not exist after fetching the tags"
    printf '%s\n%s\n' "$remote_commit" "$local_commit" | grep -Eqv "$commit_pattern" &&
        die "the tag $version does not resolve to a commit"
    [ "$remote_commit" = "$local_commit" ] ||
        die "the tag $version points to a different commit here and on origin: refusing a substituted tag"
    target_commit=$local_commit

    git rev-parse --verify --quiet refs/remotes/origin/main > /dev/null || die 'origin/main is not available'
    git merge-base --is-ancestor "$target_commit" refs/remotes/origin/main ||
        die "the tag $version is not an ancestor of origin/main"

    declared=$(git show "$target_commit:package.json" 2> /dev/null | declared_version)
    [ "$declared" = "${version#v}" ] ||
        die "the package.json of $version declares version ${declared:-none}, not ${version#v}: refusing a mislabelled release"

    resolve_installed_release
    version_newer "${version#v}" "$running" ||
        die "VERSION must be newer than the running version ($running): downgrades and re-installs are refused"
}

# Sets from, from_commit and running: the release the installation is on.
resolve_installed_release() {
    head_commit=$(git rev-parse --verify 'HEAD^{commit}') || die 'could not resolve HEAD'
    from_name=${FROM:-}
    if [ -n "$from_name" ]; then
        case $from_name in
            *"$newline"*) die 'FROM must look like vX.Y.Z and name an existing release tag' ;;
        esac
        printf '%s\n' "$from_name" | grep -Eq "$version_pattern" ||
            die 'FROM must look like vX.Y.Z and name an existing release tag'
        from_commit=$(git rev-parse --verify --quiet "refs/tags/$from_name^{commit}") ||
            die "FROM names the tag $from_name, which does not exist here"
        printf '%s\n' "$from_commit" | grep -Eq "$commit_pattern" || die "FROM $from_name does not resolve to a commit"
        from=$from_name
        running=${from_name#v}
        return
    fi
    from=$(git describe --tags --exact-match HEAD 2> /dev/null | head -n 1 || true)
    printf '%s\n' "$from" | grep -Eq "$version_pattern" ||
        die 'HEAD is not exactly a release tag: pass FROM=vX.Y.Z naming the release that is running (after git pull on main, the release you upgrade from)'
    from_commit=$head_commit
    running=$(container_version)
    if [ -z "$running" ]; then
        running=$(declared_version < "$repo_root/package.json")
        printf '%s\n' "$running" | grep -Eq "$semver_pattern" ||
            die 'could not read the running version (X.Y.Z) from package.json'
    fi
}

# --- State file ----------------------------------------------------------------

# write_state: values come from the s_* variables, all validated by the caller.
write_state() {
    (
        umask 077
        mkdir -p "$state_dir"
        chmod 700 "$state_dir"
        tmp=$(mktemp "$state_dir/.last-upgrade.XXXXXX")
        {
            printf '{\n'
            printf '  "from": "%s",\n' "$s_from"
            printf '  "fromCommit": "%s",\n' "$s_from_commit"
            printf '  "to": "%s",\n' "$s_to"
            printf '  "toCommit": "%s",\n' "$s_to_commit"
            printf '  "backup": "%s",\n' "$s_backup"
            printf '  "startedAt": "%s",\n' "$s_started"
            printf '  "finishedAt": %s,\n' "$(json_or_null "${s_finished:-}")"
            printf '  "rolledBackAt": %s\n' "$(json_or_null "${s_rolled_back:-}")"
            printf '}\n'
        } > "$tmp"
        chmod 600 "$tmp"
        mv -f -- "$tmp" "$state_file"
    )
}

json_or_null() {
    if [ -z "$1" ]; then
        printf 'null'
    else
        printf '"%s"' "$1"
    fi
}

state_field() {
    sed -n "s/^  \"$1\": \"\\([^\"]*\\)\",\\{0,1\\}\$/\\1/p" "$state_file" | head -n 1
}

# Reads the state into the s_* variables; every field is validated against a
# strict pattern, so nothing taken from the file can inject a command or a path.
read_state() {
    [ -f "$state_file" ] && [ ! -L "$state_file" ] || die 'no previous upgrade to roll back (var/upgrade/last-upgrade.json is missing)'
    s_from=$(state_field from)
    s_from_commit=$(state_field fromCommit)
    s_to=$(state_field to)
    s_to_commit=$(state_field toCommit)
    s_backup=$(state_field backup)
    s_started=$(state_field startedAt)
    s_finished=$(state_field finishedAt)
    s_rolled_back=$(state_field rolledBackAt)
    printf '%s\n' "$s_from" | grep -Eq "$version_pattern|$commit_pattern" || die 'last-upgrade.json is invalid (from)'
    printf '%s\n' "$s_from_commit" | grep -Eq "$commit_pattern" || die 'last-upgrade.json is invalid (fromCommit)'
    printf '%s\n' "$s_to" | grep -Eq "$version_pattern" || die 'last-upgrade.json is invalid (to)'
    printf '%s\n' "$s_to_commit" | grep -Eq "$commit_pattern" || die 'last-upgrade.json is invalid (toCommit)'
    printf '%s\n' "$s_backup" | grep -Eq "$backup_name_pattern" || die 'last-upgrade.json is invalid (backup)'
    printf '%s\n' "$s_started" | grep -Eq "$timestamp_pattern" || die 'last-upgrade.json is invalid (startedAt)'
    for optional in "$s_finished" "$s_rolled_back"; do
        [ -z "$optional" ] || printf '%s\n' "$optional" | grep -Eq "$timestamp_pattern" ||
            die 'last-upgrade.json is invalid (timestamp)'
    done
}

# --- Upgrade -------------------------------------------------------------------

upgrade_failed() {
    printf 'upgrade: failed during: %s\n' "$1" >&2
    printf 'The pre-upgrade backup is %s. To return to the previous version and its data, run:\n' "$s_backup" >&2
    printf '  %s\n' "$(rollback_command)" >&2
    printf 'The identity file must be the offline age identity of the backup recipient.\n' >&2
    exit 1
}

# guard <description> <command...>
guard() {
    description=$1
    shift
    "$@" || upgrade_failed "$description"
}

take_pre_upgrade_backup() {
    if ! output=$(make --no-print-directory db-backup LABEL=pre-upgrade 2>&1); then
        printf '%s\n' "$output" >&2
        die 'the pre-upgrade backup failed: nothing was changed'
    fi
    printf '%s\n' "$output"
    backup_name=$(printf '%s\n' "$output" | tail -n 1 | awk '{ print $1 }')
    printf '%s\n' "$backup_name" | grep -Eq "$backup_name_pattern" ||
        die 'the pre-upgrade backup did not report a valid file name: nothing was changed'
    backup_dir=$(sh "$repo_root/scripts/backup.sh" dir) || die 'could not resolve the backup directory'
    manifest=${backup_name%.dump.age}.manifest.json
    for file in "$backup_name" "$backup_name.sha256" "$manifest"; do
        { [ -f "$backup_dir/$file" ] && [ ! -L "$backup_dir/$file" ]; } ||
            die 'the pre-upgrade backup is incomplete: nothing was changed'
    done
    make --no-print-directory db-backup-verify FILE="$backup_dir/$backup_name" IDENTITY="$IDENTITY" ||
        die 'the pre-upgrade backup does not restore in isolation: nothing was changed'
}

upgrade() {
    precheck
    acquire_lock
    printf 'Upgrading from %s (version %s) to %s\n' "$from" "$running" "$version"

    printf 'Step 1/4: pre-upgrade backup\n'
    take_pre_upgrade_backup

    s_from=$from
    s_from_commit=$from_commit
    s_to=$version
    s_to_commit=$target_commit
    s_backup=$backup_name
    s_started=$(now)
    s_finished=
    s_rolled_back=
    write_state || die 'could not write var/upgrade/last-upgrade.json: nothing was changed'
    trap 'upgrade_failed interrupted' INT TERM HUP

    printf 'Step 2/4: checking out %s\n' "$version"
    guard 'checkout' git checkout --quiet --detach "$target_commit"
    [ "$(git rev-parse HEAD)" = "$target_commit" ] || upgrade_failed 'checkout verification'
    export_version
    printf 'Step 3/4: build and migration\n'
    guard 'image build' compose build
    guard 'stopping the application' compose stop app
    guard 'database migration' make --no-print-directory migrate
    printf 'Step 4/4: start and health check\n'
    guard 'starting the application' compose up --detach --wait
    guard 'status check' make --no-print-directory status

    trap - INT TERM HUP
    s_finished=$(now)
    write_state || printf 'upgrade: could not record finishedAt in last-upgrade.json\n' >&2
    printf 'Upgrade to %s complete. HEAD is detached at the tag; git switch main returns to the branch.\n' "$version"
    printf 'The pre-upgrade backup %s is kept; make rollback restores it.\n' "$backup_name"
}

# --- Rollback ------------------------------------------------------------------

rollback_failed() {
    printf 'rollback: failed during: %s\n' "$1" >&2
    printf 'The database already holds the pre-upgrade data and the application is stopped.\n' >&2
    case $1 in
        checkout*)
            printf 'Fix the cause (no file was changed by the checkout), then run make rollback again.\n' >&2
            ;;
        *)
            printf '%s is checked out: fix the cause, then run docker compose build and make up from this checkout (make rollback no longer applies).\n' "$s_from" >&2
            ;;
    esac
    exit 1
}

rollback() {
    acquire_lock
    read_state
    [ -z "$s_rolled_back" ] || die "the last upgrade was already rolled back at $s_rolled_back"
    require_clean_tree
    git cat-file -e "$s_from_commit^{commit}" 2> /dev/null || die 'the previous commit is not available in this clone'
    [ -n "${IDENTITY:-}" ] || die 'IDENTITY is required: the offline age identity file of the backup'

    backup_dir=$(sh "$repo_root/scripts/backup.sh" dir) || die 'could not resolve the backup directory'
    backup_path=$backup_dir/$s_backup
    # Same prechecks as make db-restore, before anything is touched.
    FILE=$backup_path CONFIRM=restore-cadran sh "$repo_root/scripts/backup.sh" restore-precheck ||
        die 'the pre-upgrade backup or the identity is not usable: nothing was changed'

    printf 'Rolling back %s to %s\n' "$s_to" "$s_from"
    if [ -n "$s_finished" ]; then
        printf 'The upgrade started at %s and finished at %s: data entered since the backup is not in it.\n' "$s_started" "$s_finished"
    else
        printf 'The upgrade started at %s and never finished.\n' "$s_started"
    fi
    printf 'The restore keeps the current database as cadran_previous_* and takes a pre-restore backup first.\n'
    printf 'Step 1/3: restoring the pre-upgrade backup (the application stays stopped)\n'
    if ! CADRAN_RESTORE_LEAVE_APP_STOPPED=1 make --no-print-directory db-restore \
        FILE="$backup_path" IDENTITY="$IDENTITY" CONFIRM=restore-cadran; then
        die 'the restore did not complete: before the replacement the live database is untouched and the current version was restarted; after it the application stays stopped and the replaced database is kept as cadran_previous_*. Run make rollback again once the cause is fixed'
    fi

    printf 'Step 2/3: checking out %s\n' "$s_from"
    git checkout --quiet --detach "$s_from_commit" || rollback_failed 'checkout'
    [ "$(git rev-parse HEAD)" = "$s_from_commit" ] || rollback_failed 'checkout verification'
    export_version
    printf 'Step 3/3: build, start and health check\n'
    compose build || rollback_failed 'image build'
    compose up --detach --wait || rollback_failed 'starting the application'
    compose exec -T app php /usr/local/bin/healthcheck.php || rollback_failed 'health check'

    s_rolled_back=$(now)
    write_state || printf 'rollback: could not record rolledBackAt in last-upgrade.json\n' >&2
    printf 'Rollback complete: %s runs on its pre-upgrade data. HEAD is detached.\n' "$s_from"
    printf 'Return to the branch with git switch main (older releases lack db-restore-cleanup), then run make db-restore-cleanup once the restored data is confirmed.\n'
}

main() {
    case ${1:-} in
        upgrade) upgrade ;;
        rollback) rollback ;;
        precheck) precheck ;;
        *) die 'usage: upgrade.sh upgrade | rollback | precheck (VERSION=vX.Y.Z for upgrade and precheck)' ;;
    esac
}

main "$@"; exit $?
