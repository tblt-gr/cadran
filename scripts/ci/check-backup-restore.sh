#!/bin/sh
# Exercises the encrypted backup pipeline end to end (OPS-001):
#   1. retention on dated dummy names, with a hand-computed expected set;
#   2. a real encrypted backup of a throwaway database migrated and seeded
#      through the tooling image;
#   3. a failing backup that must leave the previous one intact.
#
# The database under test is cadran_test<TEST_TOKEN>, never the development
# database. Run from anywhere; needs Docker, GNU date and make.
set -eu

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$repo_root"

scratch=$(mktemp -d)
chmod 700 "$scratch"
backup_script="sh scripts/backup.sh"
failures=0

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    failures=$((failures + 1))
}

assert_equal() {
    # assert_equal <description> <expected> <actual>
    if [ "$2" != "$3" ]; then
        fail "$1"
        printf 'expected:\n%s\nactual:\n%s\n' "$2" "$3" >&2
    fi
}

cleanup() {
    if [ -n "${database_created:-}" ]; then
        run_tools doctrine:database:drop --force --if-exists --no-interaction >/dev/null 2>&1 || true
    fi
    rm -rf "$scratch"
}
trap cleanup EXIT

compose_env=.env.example
if [ -f .env.local ]; then
    compose_env=.env.local
fi
compose="docker compose --env-file $compose_env"

# --- 1. Retention on dummy names --------------------------------------------

make backup-image >/dev/null

# Reference clock: Monday 2026-10-12 12:00:00 UTC.
# Daily window (age < 7 days): 12, 11 and 6 October.
# Weekly window (4 ISO weeks): weeks starting 21 Sep, 28 Sep, 5 Oct, 12 Oct.
# Monthly window (12 months): November 2025 to October 2026.
# pre-upgrade / pre-restore window: age < 90 days.
now=$(date -u -d '2026-10-12 12:00:00' +%s)
export CADRAN_BACKUP_ALLOW_TEST_CLOCK=1
retention_dir="$scratch/retention"
mkdir "$retention_dir"

kept_names='
cadran-20261012-020000-daily
cadran-20261011-020000-daily
cadran-20261006-020000-daily
cadran-20260930-020000-daily
cadran-20260922-020000-daily
cadran-20260915-100000-pre-restore
cadran-20260831-020000-daily
cadran-20260801-100000-pre-upgrade
cadran-20260720-020000-daily
cadran-20251130-020000-daily'
removed_names='
cadran-20261005-020000-daily
cadran-20260929-020000-daily
cadran-20260901-020000-daily
cadran-20260815-020000-daily
cadran-20260714-100000-pre-upgrade
cadran-20251031-020000-daily
cadran-20250101-000000-manual
cadran-20241210-100000-pre-upgrade'

for name in $kept_names $removed_names; do
    for suffix in .dump.age .dump.age.sha256 .manifest.json; do
        : > "$retention_dir/$name$suffix"
    done
done

# Foreign material: wrong label, partial file, free-form file, orphan sidecar,
# and a symbolic link named like a removable backup that points outside.
outside="$scratch/outside"
mkdir "$outside"
: > "$outside/precious.dump.age"
: > "$retention_dir/notes.txt"
: > "$retention_dir/cadran-20261012-020000-weird.dump.age"
: > "$retention_dir/.cadran-20261012-030000-daily.dump.age.partial"
: > "$retention_dir/.cadran-20260101-030000-daily.dump.age.partial"
touch -d '3 days ago' "$retention_dir/.cadran-20260101-030000-daily.dump.age.partial"
: > "$retention_dir/cadran-20200101-000000-daily.dump.age.sha256"
ln -s "$outside/precious.dump.age" "$retention_dir/cadran-20200101-000000-manual.dump.age"
mkdir "$retention_dir/subdir"
: > "$retention_dir/subdir/cadran-20200101-000000-daily.dump.age"

expected_plan=$(
    {
        for name in $kept_names; do printf 'keep %s.dump.age\n' "$name"; done
        for name in $removed_names; do printf 'remove %s.dump.age\n' "$name"; done
    } | sort
)

plan=$(CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune --dry-run |
    grep -E '^(keep|remove) ' | sort)
assert_equal 'dry-run plan matches the hand-computed retention set' "$expected_plan" "$plan"

foreign=$(CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune --dry-run |
    grep -E '^foreign ' | sort)
expected_foreign='foreign .cadran-20261012-030000-daily.dump.age.partial
foreign cadran-20200101-000000-daily.dump.age.sha256
foreign cadran-20200101-000000-manual.dump.age
foreign cadran-20261012-020000-weird.dump.age
foreign notes.txt
foreign subdir'
assert_equal 'foreign files are listed' "$(printf '%s\n' "$expected_foreign" | sort)" "$(printf '%s\n' "$foreign" | sort)"

before=$(find "$retention_dir" -mindepth 1 | wc -l)
CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune --dry-run >/dev/null
assert_equal 'dry-run removes nothing' "$before" "$(find "$retention_dir" -mindepth 1 | wc -l)"

stale=$(CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune --dry-run | grep -E '^stale-partial ')
assert_equal 'dry-run reports only the stale partial file' 'stale-partial .cadran-20260101-030000-daily.dump.age.partial' "$stale"
CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune >/dev/null
[ ! -e "$retention_dir/.cadran-20260101-030000-daily.dump.age.partial" ] || fail 'stale partial file was kept'
for name in $kept_names; do
    for suffix in .dump.age .dump.age.sha256 .manifest.json; do
        [ -e "$retention_dir/$name$suffix" ] || fail "kept backup lost a file: $name$suffix"
    done
done
for name in $removed_names; do
    for suffix in .dump.age .dump.age.sha256 .manifest.json; do
        [ ! -e "$retention_dir/$name$suffix" ] || fail "removed backup left a file: $name$suffix"
    done
done
for survivor in notes.txt cadran-20261012-020000-weird.dump.age \
    .cadran-20261012-030000-daily.dump.age.partial cadran-20200101-000000-daily.dump.age.sha256 \
    subdir/cadran-20200101-000000-daily.dump.age; do
    [ -e "$retention_dir/$survivor" ] || fail "foreign file was deleted: $survivor"
done
[ -L "$retention_dir/cadran-20200101-000000-manual.dump.age" ] || fail 'symbolic link was deleted'
[ -e "$outside/precious.dump.age" ] || fail 'a file outside the backup directory was deleted'

# The newest backup always survives, whatever its age.
old_now=$(date -u -d '2030-01-01 00:00:00' +%s)
CADRAN_BACKUP_DIR="$retention_dir" CADRAN_BACKUP_NOW="$old_now" $backup_script prune >/dev/null
remaining=$(find "$retention_dir" -maxdepth 1 -name 'cadran-2*-*.dump.age' ! -name '*weird*' -type f | sort)
assert_equal 'only the newest backup survives a far-future prune' \
    "$retention_dir/cadran-20261012-020000-daily.dump.age" "$remaining"

# Invalid and future-dated names are foreign, never deleted.
odd_dir="$scratch/odd"
mkdir "$odd_dir"
: > "$odd_dir/cadran-20261312-020000-daily.dump.age"
: > "$odd_dir/cadran-20260230-020000-daily.dump.age"
: > "$odd_dir/cadran-20271001-020000-daily.dump.age"
: > "$odd_dir/cadran-20261012-020000-daily.dump.age"
odd_plan=$(CADRAN_BACKUP_DIR="$odd_dir" CADRAN_BACKUP_NOW="$now" $backup_script prune | grep -E '^(keep|remove|foreign) ' | sort)
assert_equal 'out-of-range and future-dated names are left alone' 'foreign cadran-20260230-020000-daily.dump.age
foreign cadran-20261312-020000-daily.dump.age
foreign cadran-20271001-020000-daily.dump.age
keep cadran-20261012-020000-daily.dump.age' "$odd_plan"
assert_equal 'foreign candidates are not deleted' 4 "$(find "$odd_dir" -type f | wc -l)"

# Without the explicit test switch the reference clock cannot be forged.
clock_dir="$scratch/clock"
mkdir "$clock_dir"
: > "$clock_dir/cadran-$(date -u -d '1 day ago' +%Y%m%d)-020000-daily.dump.age"
: > "$clock_dir/cadran-$(date -u -d '2 days ago' +%Y%m%d)-020000-daily.dump.age"
if env -u CADRAN_BACKUP_ALLOW_TEST_CLOCK CADRAN_BACKUP_DIR="$clock_dir" CADRAN_BACKUP_NOW="$old_now" \
    $backup_script prune --dry-run | grep -q '^remove '; then
    fail 'CADRAN_BACKUP_NOW must be ignored without CADRAN_BACKUP_ALLOW_TEST_CLOCK'
fi
unset CADRAN_BACKUP_ALLOW_TEST_CLOCK

# --- Directory and identity refusals ----------------------------------------

ln -s "$retention_dir" "$scratch/linked"
if CADRAN_BACKUP_DIR="$scratch/linked" $backup_script prune --dry-run >/dev/null 2>&1; then
    fail 'a symbolic-link backup directory must be refused'
fi
if CADRAN_BACKUP_DIR="$scratch/missing" $backup_script prune --dry-run >/dev/null 2>&1; then
    fail 'a missing backup directory must be refused'
fi
[ ! -e "$scratch/missing" ] || fail 'a configured but missing backup directory must not be created'
if CADRAN_BACKUP_DIR=/ $backup_script prune --dry-run >/dev/null 2>&1; then
    fail 'the filesystem root must be refused as backup directory'
fi
if CADRAN_BACKUP_DIR="$repo_root" $backup_script prune --dry-run >/dev/null 2>&1; then
    fail 'the repository root must be refused as backup directory'
fi

# Only the untouched default directory is created on demand.
if [ ! -e "$repo_root/backups" ] && ! grep -q '^CADRAN_BACKUP_DIR=' .env.local 2>/dev/null; then
    env -u CADRAN_BACKUP_DIR $backup_script health >/dev/null
    assert_equal 'default backup directory is created with mode 700' 700 "$(stat -c %a "$repo_root/backups")"
    rmdir "$repo_root/backups"
fi

# --- 2. Real encrypted backup ------------------------------------------------

run_tools() {
    docker run --rm --user "$(id -u):$(id -g)" --env HOME=/tmp \
        --env APP_ENV=test --env "TEST_TOKEN=${TEST_TOKEN}" \
        --mount "type=bind,source=$repo_root,target=/workspace" --workdir /workspace \
        --network cadran_internal \
        --mount "type=bind,source=$repo_root/var/docker-secrets/db_password,target=/run/secrets/db_password,readonly" \
        cadran_tools:local php apps/api/bin/console --no-debug --env=test "$@"
}

sh scripts/init-local.sh
make tools >/dev/null
$compose build db >/dev/null
$compose up --detach --wait db

TEST_TOKEN=backupcheck
export TEST_TOKEN
CADRAN_DB_NAME="cadran_test$TEST_TOKEN"
export CADRAN_DB_NAME
database_created=1
run_tools doctrine:database:drop --force --if-exists --no-interaction >/dev/null
run_tools doctrine:database:create --no-interaction >/dev/null
run_tools doctrine:migrations:migrate --no-interaction --allow-no-migration >/dev/null
run_tools app:demo:seed >/dev/null 2>&1 || printf 'note: demo seed unavailable, backing up the migrated schema only\n'

backups="$scratch/backups"
mkdir "$backups"
identity="$scratch/identity/identity.txt"
recipient_file="$scratch/recipient"
CADRAN_BACKUP_DIR="$backups" CADRAN_BACKUP_RECIPIENT_FILE="$recipient_file" IDENTITY="$identity" \
    $backup_script key >"$scratch/key.out"
[ -s "$identity" ] || fail 'identity was not written'
assert_equal 'identity mode is 600' 600 "$(stat -c %a "$identity")"
assert_equal 'recipient mode is 600' 600 "$(stat -c %a "$recipient_file")"
if grep -q 'AGE-SECRET-KEY' "$scratch/key.out"; then
    fail 'backup-key printed the private key'
fi
if CADRAN_BACKUP_DIR="$backups" CADRAN_BACKUP_RECIPIENT_FILE="$scratch/recipient2" IDENTITY="$identity" \
    $backup_script key >/dev/null 2>&1; then
    fail 'backup-key must refuse to overwrite an identity'
fi
if CADRAN_BACKUP_DIR="$backups" CADRAN_BACKUP_RECIPIENT_FILE="$scratch/recipient3" IDENTITY="$repo_root/identity.txt" \
    $backup_script key >/dev/null 2>&1; then
    fail 'backup-key must refuse an identity path inside the repository'
fi
if CADRAN_BACKUP_DIR="$backups" CADRAN_BACKUP_RECIPIENT_FILE="$scratch/recipient4" IDENTITY="$backups/identity.txt" \
    $backup_script key >/dev/null 2>&1; then
    fail 'backup-key must refuse an identity path inside the backup directory'
fi

export CADRAN_BACKUP_RECIPIENT_FILE="$recipient_file"
export CADRAN_BACKUP_DIR="$backups"

if CADRAN_BACKUP_RECIPIENT_FILE="$scratch/absent" $backup_script create 'bad label' >/dev/null 2>&1; then
    fail 'an unknown label must be refused'
fi
if CADRAN_BACKUP_RECIPIENT_FILE="$scratch/absent" $backup_script create manual >/dev/null 2>&1; then
    fail 'a missing recipient must be refused'
fi

summary=$($backup_script create manual)
assert_equal 'create prints a single summary line' 1 "$(printf '%s\n' "$summary" | wc -l)"
dump=$(find "$backups" -maxdepth 1 -name 'cadran-*-manual.dump.age' | head -n 1)
[ -n "$dump" ] || fail 'no encrypted dump was produced'
base=${dump%.dump.age}
[ -f "$dump.sha256" ] || fail 'checksum file is missing'
[ -f "$base.manifest.json" ] || fail 'manifest is missing'
assert_equal 'dump mode is 600' 600 "$(stat -c %a "$dump")"
assert_equal 'checksum mode is 600' 600 "$(stat -c %a "$dump.sha256")"
assert_equal 'manifest mode is 600' 600 "$(stat -c %a "$base.manifest.json")"
if [ -n "$(find "$backups" -maxdepth 1 -name '.*partial')" ]; then
    fail 'partial files were left behind'
fi
if grep -aq 'PGDMP' "$dump"; then
    fail 'the encrypted file exposes the plain PGDMP header'
fi
if printf '%s' "$summary" | grep -Eqi 'age1|password|PGDMP'; then
    fail 'create output leaks secret or content'
fi

(cd "$backups" && sha256sum --check --status "$(basename "$dump").sha256") || fail 'checksum does not match'
manifest=$(cat "$base.manifest.json")
actual_sha=$(sha256sum "$dump" | cut -d' ' -f1)
actual_size=$(wc -c <"$dump" | tr -d ' ')
case $manifest in *"\"sha256\": \"$actual_sha\""*) ;; *) fail 'manifest sha256 differs' ;; esac
case $manifest in *"\"sizeBytes\": $actual_size"*) ;; *) fail 'manifest size differs' ;; esac
case $manifest in *'"format": 1'*'"label": "manual"'*'"postgresVersion": "18"'*) ;; *) fail 'manifest fields are wrong' ;; esac
case $manifest in *'"schemaVersion": "DoctrineMigrations\\Version'*) ;; *) fail 'manifest schema version is wrong' ;; esac

# The backup must be restorable with the offline identity.
decrypted_header=$(docker run --rm --user "$(id -u):$(id -g)" --entrypoint sh \
    --mount "type=bind,source=$backups,target=/backups,readonly" \
    --mount "type=bind,source=$identity,target=/identity.txt,readonly" \
    cadran_backup:local -c 'age --decrypt --identity /identity.txt /backups/"$1" | head -c 5' sh "$(basename "$dump")")
assert_equal 'decrypted dump is a custom-format archive' PGDMP "$decrypted_header"

# An orphan manifest (no dump) must not hide the real newest backup.
printf '{\n  "createdAt": "2999-01-01T00:00:00Z",\n  "label": "daily",\n}\n' >"$backups/cadran-29990101-000000-daily.manifest.json"
health=$($backup_script health)
case $health in *'(manual)'*) ;; *) fail "health must skip a manifest without a dump: $health" ;; esac
rm -- "$backups/cadran-29990101-000000-daily.manifest.json"
only_orphan="$scratch/orphan"
mkdir "$only_orphan"
cp "$base.manifest.json" "$only_orphan/"
case $(CADRAN_BACKUP_DIR="$only_orphan" $backup_script health) in *WARNING*) ;; *) fail 'health must warn when only an orphan manifest exists' ;; esac

# --- 3. A failing backup leaves the previous one intact ----------------------

printf 'wrong-password\n' >"$scratch/wrong_password"
chmod 600 "$scratch/wrong_password"
before_listing=$(cd "$backups" && ls -A | sort)
if sleep 1 && CADRAN_DB_PASSWORD_FILE="$scratch/wrong_password" $backup_script create manual >/dev/null 2>&1; then
    fail 'a backup with a wrong database password must fail'
fi
assert_equal 'a failed backup leaves the directory untouched' "$before_listing" "$(cd "$backups" && ls -A | sort)"
(cd "$backups" && sha256sum --check --status "$(basename "$dump").sha256") || fail 'first backup was altered by the failure'

if [ "$failures" -ne 0 ]; then
    printf '%s backup check(s) failed.\n' "$failures" >&2
    exit 1
fi
printf 'Backup, retention and failure checks passed.\n'
