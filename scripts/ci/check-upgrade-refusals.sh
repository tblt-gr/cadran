#!/bin/sh
# Exercises `make upgrade` and `make rollback` (OPS-003) in a temporary clone with
# a bare "origin". Part 1: every refusal (malformed version, uncommitted changes,
# unknown tag, tag not on origin/main, tag substitution, downgrade, same version)
# must happen before any Docker or make call, which are replaced by stubs that
# record their use; the version comparison is numeric (0.9.0 -> 0.10.0 is an
# upgrade). Part 2: with recording stubs that succeed, the order of the real flow
# (backup, then checkout, build, stop, migrate, start; rollback restores before it
# checks out), the state file, the failure paths and the printed rollback command.
#
# Needs git and a POSIX shell only; never uses Docker or the real repository.
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
scratch=$(mktemp -d)
failures=0

cleanup() { rm -rf "$scratch"; }
trap cleanup EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    failures=$((failures + 1))
}

printf 'secret\n' > "$scratch/db_password"
printf 'AGE-SECRET-KEY-SENTINEL-DO-NOT-PRINT\n' > "$scratch/identity.txt"
sentinel=AGE-SECRET-KEY-SENTINEL-DO-NOT-PRINT
test_identity=$scratch/identity.txt
test_from=

# No Docker, no make: any call is recorded and fails.
stubs=$scratch/stubs
mkdir "$stubs"
calls=$scratch/stub-calls
: > "$calls"
for tool in docker make; do
    printf '#!/bin/sh\necho "%s $*" >> "%s"\nexit 99\n' "$tool" "$calls" > "$stubs/$tool"
    chmod +x "$stubs/$tool"
done

export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=ci GIT_AUTHOR_EMAIL=ci@example.invalid
export GIT_COMMITTER_NAME=ci GIT_COMMITTER_EMAIL=ci@example.invalid

origin=$scratch/origin.git
work=$scratch/work
clone=$scratch/clone
git init --quiet --bare --initial-branch=main "$origin"
git init --quiet --initial-branch=main "$work"

release() {
    # release <package version> [tag] [pad]: a commit whose package.json carries the
    # version, tagged v<version> unless another tag is given. "pad" gives the commit a
    # different copy of upgrade.sh, as a newer release has: checking it out rewrites
    # the script a running upgrade was started from.
    mkdir -p "$work/scripts" "$work/apps/api/migrations"
    cp "$repo_root/scripts/upgrade.sh" "$repo_root/scripts/backup.sh" "$work/scripts/"
    if [ "${3:-}" = pad ]; then
        {
            sed -n 1p "$repo_root/scripts/upgrade.sh"
            i=0
            while [ "$i" -lt 80 ]; do
                printf '# padding line %s\n' "$i"
                i=$((i + 1))
            done
            sed -n '2,$p' "$repo_root/scripts/upgrade.sh"
        } > "$work/scripts/upgrade.sh"
    fi
    printf '<?php\n' > "$work/apps/api/migrations/Version20260101000000.php"
    printf 'var/\nbackups/\n' > "$work/.gitignore"
    printf '{\n  "name": "cadran",\n  "version": "%s",\n  "private": true\n}\n' "$1" > "$work/package.json"
    git -C "$work" add -A
    git -C "$work" commit --quiet -m "release $1"
    git -C "$work" tag "${2:-v$1}"
}

release 0.9.0
release 0.10.0
release 0.11.0 v0.11.0 pad
git -C "$work" remote add origin "$origin"
git -C "$work" push --quiet origin main --tags

# A release tag that lives on a side branch, never merged into main.
git -C "$work" checkout --quiet -b side v0.9.0
printf 'side\n' > "$work/side.txt"
git -C "$work" add -A
git -C "$work" commit --quiet -m 'side work'
git -C "$work" tag v0.99.0
git -C "$work" push --quiet origin side v0.99.0
git -C "$work" checkout --quiet main

# Tag substitution: origin knows v0.12.0 on main; the clone is given another commit under that name.
release 0.12.0
git -C "$work" push --quiet origin main v0.12.0

# A tag whose package.json declares another version.
release 0.13.1 v0.13.0
git -C "$work" push --quiet origin main v0.13.0

git clone --quiet "$origin" "$clone"

upgrade() {
    # upgrade <subcommand> [VERSION]: runs the clone's copy, output in $scratch/out
    (cd "$clone" && VERSION=${2-} IDENTITY=$test_identity FROM=$test_from PATH="$stubs:$PATH" sh scripts/upgrade.sh "$1") > "$scratch/out" 2>&1
}

at() {
    git -C "$clone" checkout --quiet --detach "$1"
}

expect_refused() {
    # expect_refused <description> <version> <message fragment>
    if upgrade precheck "$2"; then
        fail "$1: the upgrade was accepted"
    elif ! grep -qF -- "$3" "$scratch/out"; then
        fail "$1: unexpected refusal message: $(cat "$scratch/out")"
    fi
    # Only the read-only query of the running version may reach Docker.
    if grep -qv 'printenv CADRAN_VERSION' "$calls"; then
        fail "$1: docker or make was called before the refusal"
    fi
    : > "$calls"
}

expect_accepted() {
    if ! upgrade precheck "$2"; then
        fail "$1: the upgrade was refused: $(cat "$scratch/out")"
    fi
    if grep -qv 'printenv CADRAN_VERSION' "$calls"; then
        fail "$1: docker or make was called by the precheck"
    fi
    : > "$calls"
}

at v0.10.0

# --- malformed versions --------------------------------------------------------
expect_refused 'empty version' '' 'VERSION'
expect_refused 'missing v prefix' '0.11.0' 'vX.Y.Z'
expect_refused 'two components' 'v0.11' 'vX.Y.Z'
expect_refused 'pre-release suffix' 'v0.11.0-rc1' 'vX.Y.Z'
expect_refused 'branch name' 'main' 'vX.Y.Z'
expect_refused 'option-like value' '--help' 'vX.Y.Z'
expect_refused 'command injection attempt' 'v0.11.0;touch pwned' 'vX.Y.Z'
expect_refused 'line break after a valid version' 'v0.11.0
rm' 'vX.Y.Z'
[ ! -e "$clone/pwned" ] || fail 'a malformed VERSION was executed'

# --- tags --------------------------------------------------------------------
expect_refused 'unknown tag' 'v9.9.9' 'does not exist'
expect_refused 'tag outside origin/main' 'v0.99.0' 'origin/main'

# --- same version and downgrades (numeric, not lexical: 0.9.0 sorts after 0.10.0) --
expect_refused 'same version' 'v0.10.0' 'newer than the running version'
expect_refused 'downgrade' 'v0.9.0' 'newer than the running version'

# --- uncommitted changes ------------------------------------------------------
printf 'changed\n' >> "$clone/package.json"
expect_refused 'modified tracked file' 'v0.11.0' 'uncommitted'
git -C "$clone" checkout --quiet -- package.json
printf 'new\n' > "$clone/untracked.txt"
expect_refused 'untracked file' 'v0.11.0' 'uncommitted'
rm "$clone/untracked.txt"

# --- tag substitution ---------------------------------------------------------
git -C "$clone" tag --delete v0.12.0 >/dev/null
git -C "$clone" tag v0.12.0 v0.9.0
expect_refused 'local tag differing from origin' 'v0.12.0' 'rejected'
git -C "$clone" tag --delete v0.12.0 >/dev/null

# --- the release declared by the tag ------------------------------------------
expect_refused 'package.json differing from the tag' 'v0.13.0' 'package.json'

# --- the identity is mandatory ---------------------------------------------------
test_identity=
expect_refused 'missing IDENTITY' 'v0.11.0' 'IDENTITY'
test_identity=$scratch/absent-identity.txt
expect_refused 'IDENTITY that is not a file' 'v0.11.0' 'IDENTITY'
test_identity=$scratch/identity.txt

# --- HEAD must be a release, or FROM names the release that is running --------
git -C "$clone" commit --quiet --allow-empty -m 'work after the release'
expect_refused 'untagged HEAD without FROM' 'v0.11.0' 'FROM'
test_from=v0.10.0
expect_accepted 'untagged HEAD with FROM naming the running release' 'v0.11.0'
test_from=v9.9.9
expect_refused 'FROM naming an unknown tag' 'v0.11.0' 'FROM'
test_from=0.10.0
expect_refused 'malformed FROM' 'v0.11.0' 'FROM'
test_from=v0.11.0
expect_refused 'FROM naming a release that is not older' 'v0.11.0' 'newer than the running version'
test_from=
at v0.10.0

# --- accepted upgrades --------------------------------------------------------
expect_accepted 'next release' 'v0.11.0'
at v0.9.0
expect_accepted 'numeric comparison 0.9.0 to 0.10.0' 'v0.10.0'

# A passing precheck must not move HEAD or the tags.
[ "$(git -C "$clone" rev-parse HEAD)" = "$(git -C "$clone" rev-parse 'v0.9.0^{commit}')" ] ||
    fail 'the precheck moved HEAD'

# =============================================================================
# Part 2: the flow, with stubs that succeed and record every call.
# =============================================================================

flow_stubs=$scratch/flow-stubs
flow_log=$scratch/flow.log
backups=$scratch/backups
mkdir "$flow_stubs" "$backups"
chmod 700 "$backups"
cat > "$flow_stubs/make" <<'STUB'
#!/bin/sh
target=$1
[ "$target" != --no-print-directory ] || target=$2
echo "make $target head=$(git rev-parse --short HEAD) leave=${CADRAN_RESTORE_LEAVE_APP_STOPPED:-} version=${CADRAN_VERSION:-}" >> "$STUB_LOG"
[ "${STUB_FAIL:-}" != "$target" ] || exit 1
[ "${STUB_SIGNAL:-}" != "$target" ] || kill -TERM "$PPID"
if [ "$target" = db-backup ]; then
    name=cadran-20260101-000000-pre-upgrade
    : > "$CADRAN_BACKUP_DIR/$name.dump.age"
    : > "$CADRAN_BACKUP_DIR/$name.dump.age.sha256"
    : > "$CADRAN_BACKUP_DIR/$name.manifest.json"
    echo "$name.dump.age 123 bytes in 1s"
fi
exit 0
STUB
cat > "$flow_stubs/docker" <<'STUB'
#!/bin/sh
echo "docker $* head=$(git rev-parse --short HEAD) version=${CADRAN_VERSION:-}" >> "$STUB_LOG"
case " $* " in
    *" printenv "*)
        # The version query of the running container: silent failure means the stack is down.
        [ -n "${STUB_RUNNING_VERSION:-}" ] || exit 1
        echo "$STUB_RUNNING_VERSION"
        exit 0
        ;;
esac
case " $* " in
    *" ${STUB_FAIL:-no-such-docker-command} "*) exit 1 ;;
esac
exit 0
STUB
chmod +x "$flow_stubs/make" "$flow_stubs/docker"

flow() {
    # flow <subcommand> [VERSION]: runs the clone's copy with the succeeding stubs
    (
        cd "$clone"
        VERSION=${2-} IDENTITY=$scratch/identity.txt FROM=${flow_from:-} STUB_RUNNING_VERSION=${stub_running:-} \
            PATH="$flow_stubs:$PATH" STUB_LOG=$flow_log \
            CADRAN_BACKUP_DIR=$backups CADRAN_DB_PASSWORD_FILE=$scratch/db_password \
            sh scripts/upgrade.sh "$1"
    ) > "$scratch/out" 2>&1
}

reset_flow() {
    git -C "$clone" checkout --quiet --detach v0.10.0
    rm -rf "$clone/var"
    rm -f "$backups"/cadran-*
    : > "$flow_log"
}

position() {
    # position <pattern>: line number of the first log line matching the pattern, 0 when absent
    grep -n -- "$1" "$flow_log" | head -n 1 | cut -d: -f1 | grep . || echo 0
}

assert_before() {
    # assert_before <description> <earlier pattern> <later pattern>
    first=$(position "$2")
    second=$(position "$3")
    if [ "$first" -eq 0 ] || [ "$second" -eq 0 ] || [ "$first" -ge "$second" ]; then
        fail "$1 (positions $first and $second)"
        cat "$flow_log" >&2
    fi
}

assert_no_leak() {
    if grep -qF -- "$sentinel" "$scratch/out" "$flow_log" 2>/dev/null; then
        fail "$1: the identity content leaked"
    fi
}

target_short=$(git -C "$clone" rev-parse --short v0.11.0)
previous_short=$(git -C "$clone" rev-parse --short v0.10.0)

# --- Successful upgrade and rollback ------------------------------------------
reset_flow
if ! flow upgrade v0.11.0; then
    fail "upgrade failed: $(cat "$scratch/out")"
fi
assert_no_leak 'upgrade'
assert_before 'the backup must precede the checkout' "make db-backup head=$previous_short" "docker compose --env-file .env.example build head=$target_short"
assert_before 'the backup must be verified before the checkout' 'make db-backup-verify' ' build head='
[ ! -d "$clone/var/upgrade/lock" ] || fail 'the lock must be released after an upgrade'
assert_before 'the app must be stopped before the migration' ' stop app ' 'make migrate'
assert_before 'the migration must follow the build' ' build ' 'make migrate'
assert_before 'the app must start after the migration' 'make migrate' ' up --detach --wait'
assert_before 'the status must be read last' ' up --detach --wait' 'make status'
if ! grep -q "docker .* build head=$target_short version=0.11.0" "$flow_log"; then
    fail 'the image must be built with the version of the tag being installed'
fi
state=$clone/var/upgrade/last-upgrade.json
[ "$(stat -c %a "$clone/var/upgrade")" = 700 ] || fail 'var/upgrade must be mode 700'
[ "$(stat -c %a "$state")" = 600 ] || fail 'last-upgrade.json must be mode 600'
grep -q '"from": "v0.10.0"' "$state" || fail 'last-upgrade.json must record the previous tag'
grep -q '"to": "v0.11.0"' "$state" || fail 'last-upgrade.json must record the target tag'
grep -q '"backup": "cadran-20260101-000000-pre-upgrade.dump.age"' "$state" || fail 'last-upgrade.json must record the backup file name'
grep -q '"finishedAt": "' "$state" || fail 'last-upgrade.json must record finishedAt'
[ "$(git -C "$clone" rev-parse --short HEAD)" = "$target_short" ] || fail 'HEAD must be on the installed tag'

: > "$flow_log"
if ! flow rollback; then
    fail "rollback failed: $(cat "$scratch/out")"
fi
assert_no_leak 'rollback'
assert_before 'the restore must precede the checkout of the previous version' "make db-restore head=$target_short leave=1" "docker compose --env-file .env.example build head=$previous_short"
assert_before 'the previous version must start after the restore' 'make db-restore' ' up --detach --wait'
assert_before 'health must be verified last' ' up --detach --wait' ' exec -T app php /usr/local/bin/healthcheck.php'
if ! grep -q "docker .* build head=$previous_short version=0.10.0" "$flow_log"; then
    fail 'the rolled-back image must be built with the previous version'
fi
if grep -q 'migrate' "$flow_log"; then
    fail 'a rollback must never migrate'
fi
grep -q '"rolledBackAt": "' "$state" || fail 'last-upgrade.json must record rolledBackAt'
[ "$(git -C "$clone" rev-parse --short HEAD)" = "$previous_short" ] || fail 'HEAD must be back on the previous version'
grep -q 'git switch main' "$scratch/out" || fail 'the rollback must tell how to return to the branch before the cleanup'
grep -q 'finished at' "$scratch/out" || fail 'the rollback must say when the upgrade finished'
[ ! -d "$clone/var/upgrade/lock" ] || fail 'the lock must be released after a rollback'
if flow rollback; then
    fail 'a second rollback of the same upgrade must be refused'
fi

# --- Rollback without a previous upgrade, without identity --------------------
reset_flow
if flow rollback; then
    fail 'rollback without last-upgrade.json must be refused'
elif ! grep -q 'no previous upgrade' "$scratch/out"; then
    fail 'rollback without last-upgrade.json: unexpected message'
fi

# --- A tampered state file is refused before anything runs --------------------
mkdir -p "$clone/var/upgrade"
printf '{\n  "from": "v0.10.0",\n  "fromCommit": "$(touch pwned)",\n  "to": "v0.11.0",\n  "toCommit": "%s",\n  "backup": "../../etc/passwd",\n  "startedAt": "2026-01-01T00:00:00Z",\n  "finishedAt": null,\n  "rolledBackAt": null\n}\n' "$(git -C "$clone" rev-parse v0.11.0)" > "$clone/var/upgrade/last-upgrade.json"
if flow rollback; then
    fail 'a tampered last-upgrade.json must be refused'
fi
[ ! -s "$flow_log" ] || fail 'a tampered last-upgrade.json must be refused before any command runs'
[ ! -e "$clone/pwned" ] || fail 'a value from last-upgrade.json was executed'

# --- A failing backup stops everything before the first change ----------------
reset_flow
if STUB_FAIL=db-backup flow upgrade v0.11.0; then
    fail 'an upgrade with a failed backup must stop'
fi
[ "$(git -C "$clone" rev-parse --short HEAD)" = "$previous_short" ] || fail 'a failed backup must leave HEAD untouched'
[ ! -e "$clone/var/upgrade/last-upgrade.json" ] || fail 'a failed backup must not write last-upgrade.json'
if grep -qE 'migrate| build | stop app' "$flow_log"; then
    fail 'nothing may run after a failed backup'
fi

# --- A failing migration stops, prints the rollback command, never starts -----
reset_flow
if STUB_FAIL=migrate flow upgrade v0.11.0; then
    fail 'an upgrade with a failed migration must stop'
fi
assert_no_leak 'failed migration'
grep -qF "make rollback IDENTITY=$scratch/identity.txt" "$scratch/out" ||
    fail "a failed migration must print the exact rollback command: $(cat "$scratch/out")"
if grep -qE ' up --detach|make status' "$flow_log"; then
    fail 'the application must not start after a failed migration'
fi
grep -q '"finishedAt": null' "$clone/var/upgrade/last-upgrade.json" || fail 'a failed upgrade must not record finishedAt'

# --- A failing build stops before the migration -------------------------------
reset_flow
if STUB_FAIL=build flow upgrade v0.11.0; then
    fail 'an upgrade with a failed build must stop'
fi
grep -qF 'make rollback IDENTITY=' "$scratch/out" || fail 'a failed build must print the rollback command'
if grep -q 'make migrate' "$flow_log"; then
    fail 'the migration must not run after a failed build'
fi

# --- A backup that does not verify stops everything before the first change ---
reset_flow
if STUB_FAIL=db-backup-verify flow upgrade v0.11.0; then
    fail 'an upgrade whose backup does not verify must stop'
fi
[ "$(git -C "$clone" rev-parse --short HEAD)" = "$previous_short" ] || fail 'a failed verification must leave HEAD untouched'
[ ! -e "$clone/var/upgrade/last-upgrade.json" ] || fail 'a failed verification must not write last-upgrade.json'
if grep -qE 'migrate| build | stop app' "$flow_log"; then
    fail 'nothing may run after a backup that does not verify'
fi

# --- One upgrade or rollback at a time ----------------------------------------
reset_flow
mkdir -p "$clone/var/upgrade/lock"
if flow upgrade v0.11.0; then
    fail 'an upgrade must be refused while another one holds the lock'
fi
grep -q 'already running' "$scratch/out" || fail 'the lock refusal must say another run is in progress'
if grep -q 'make db-backup' "$flow_log"; then
    fail 'nothing may run while the lock is held'
fi
if flow rollback; then
    fail 'a rollback must be refused while another run holds the lock'
fi
[ -d "$clone/var/upgrade/lock" ] || fail 'a refused run must not release the lock of another run'
rmdir "$clone/var/upgrade/lock"

# --- An interruption after the state file prints the rollback command ---------
reset_flow
if STUB_SIGNAL=migrate flow upgrade v0.11.0; then
    fail 'an interrupted upgrade must stop'
fi
grep -q 'interrupted' "$scratch/out" || fail 'an interruption must be reported'
grep -qF "make rollback IDENTITY=$scratch/identity.txt" "$scratch/out" ||
    fail 'an interruption must print the rollback command'
if grep -qE ' up --detach|make status' "$flow_log"; then
    fail 'the application must not start after an interruption'
fi
[ ! -d "$clone/var/upgrade/lock" ] || fail 'the lock must be released after an interruption'

# --- The running version comes from the container when the stack is up --------
reset_flow
stub_running=0.11.0
if flow precheck v0.11.0; then
    fail 'the version reported by the running container must win over package.json'
fi
stub_running=unknown
flow precheck v0.11.0 || fail "an unknown container version must fall back to package.json: $(cat "$scratch/out")"
stub_running=
flow precheck v0.11.0 || fail "a stopped stack must fall back to package.json: $(cat "$scratch/out")"

# --- Untagged HEAD: FROM names the release that is running --------------------
reset_flow
git -C "$clone" commit --quiet --allow-empty -m 'work after the release'
flow_from=v0.10.0
if ! flow upgrade v0.11.0; then
    fail "an upgrade from an untagged HEAD with FROM failed: $(cat "$scratch/out")"
fi
grep -q '"from": "v0.10.0"' "$state" || fail 'FROM must be recorded as the previous release'
grep -q "\"fromCommit\": \"$(git -C "$clone" rev-parse v0.10.0)\"" "$state" ||
    fail 'the rollback commit must be the one of the FROM tag'
flow_from=

# =============================================================================
# Part 3: scripts/backup.sh restore in rollback mode (the app image must never
# be restarted on the restored, older schema).
# =============================================================================

reset_flow
restore_stubs=$scratch/restore-stubs
restore_backups=$scratch/restore-backups
restore_log=$scratch/restore.log
mkdir "$restore_stubs" "$restore_backups"
chmod 700 "$restore_backups"
restore_name=cadran-20260101-000000-pre-upgrade
: > "$restore_backups/$restore_name.dump.age"
: > "$restore_backups/$restore_name.dump.age.sha256"
: > "$restore_backups/$restore_name.manifest.json"
printf 'age1recipient\n' > "$scratch/recipient"
cat > "$restore_stubs/docker" <<'STUB'
#!/bin/sh
echo "docker $*" >> "$STUB_LOG"
action=
next=0
for argument in "$@"; do
    if [ "$next" = 1 ]; then
        action=$argument
        break
    fi
    [ "$argument" != cadran_backup:local ] || next=1
done
case $action in
    verify | restore-new) [ "${STUB_FAIL_ACTION:-}" != "$action" ] || exit 1 ;;
    swap)
        if [ "${STUB_SWAP:-ok}" = ok ]; then
            printf 'previous=cadran_previous_20260101000000\nfailed=cadran_failed_20260101000000\n'
        else
            echo garbage
        fi
        ;;
esac
exit 0
STUB
chmod +x "$restore_stubs/docker"

restore_run() {
    # restore_run <leave: 1 or empty>
    : > "$restore_log"
    (
        cd "$clone"
        PATH="$restore_stubs:$PATH" STUB_LOG=$restore_log CADRAN_BACKUP_DIR=$restore_backups \
            CADRAN_BACKUP_RECIPIENT_FILE=$scratch/recipient CADRAN_DB_PASSWORD_FILE=$scratch/db_password \
            FILE=$restore_backups/$restore_name.dump.age IDENTITY=$scratch/identity.txt CONFIRM=restore-cadran \
            CADRAN_RESTORE_LEAVE_APP_STOPPED=$1 sh scripts/backup.sh restore
    ) > "$scratch/out" 2>&1
}

if ! restore_run 1; then
    fail "a restore in rollback mode failed: $(cat "$scratch/out")"
fi
grep -q ' stop app' "$restore_log" || fail 'a restore must stop the app service first'
if grep -q ' up --detach' "$restore_log"; then
    fail 'a restore in rollback mode must not restart the app service'
fi

STUB_SWAP=garbage restore_run 1 && fail 'an unexpected replacement result must fail'
if grep -q ' up --detach' "$restore_log"; then
    fail 'an unexpected replacement result must not restart the newer image in rollback mode'
fi

STUB_FAIL_ACTION=restore-new restore_run 1 && fail 'a failed restore must fail'
grep -q ' up --detach' "$restore_log" ||
    fail 'a restore that failed before the replacement must restart the app on the untouched database'

if ! restore_run ''; then
    fail "a normal restore failed: $(cat "$scratch/out")"
fi
grep -q ' up --detach' "$restore_log" || fail 'a normal restore must restart the app service'

if [ "$failures" -ne 0 ]; then
    printf '%s upgrade refusal check(s) failed.\n' "$failures" >&2
    exit 1
fi
printf 'Upgrade and rollback: all checks passed.\n'
