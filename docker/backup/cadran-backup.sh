#!/bin/sh
# One-shot backup tool of the cadran_backup image. Subcommands:
#   create <label>       encrypted pg_dump of the database into /backups
#   prune [--dry-run]    apply the retention policy inside /backups
#   keygen               print a new age identity (private key) on stdout
#   verify               restore the mounted backup into cadran_restore_check, check it, drop it
#   restore-new          restore the mounted backup into cadran_restore_new and check it
#   swap                 replace the live database with cadran_restore_new (keeps the old one)
#   rollback <previous> <failed>   undo a swap
#   drop-new             drop cadran_restore_new
#   list-previous        list the cadran_previous_* databases kept after a restore
#   drop-previous        drop every cadran_previous_* database
#
# Never prints dump content, row values, the database password or the recipient.
# The restore subcommands read /restore/backup.dump.age (with .sha256 and
# .manifest.json) and /run/backup_identity, mounted read-only by the host.
set -eu
umask 077

backup_dir=/backups
password_file=/run/db_password
recipient_file=/run/backup_recipient
name_pattern='^cadran-[0-9]{8}-[0-9]{6}-(manual|daily|pre-upgrade|pre-restore)\.dump\.age$'
partial_pattern='^\.cadran-[0-9]{8}-[0-9]{6}-(manual|daily|pre-upgrade|pre-restore)\.(dump\.age|dump\.age\.sha256|manifest\.json)\.partial$'
newline='
'
restore_dir=/restore
identity_file=/run/backup_identity
check_database=cadran_restore_check
new_database=cadran_restore_new
previous_pattern='^cadran_previous_[0-9]{14}$'
failed_pattern='^cadran_failed_[0-9]{14}$'
schema_pattern='^DoctrineMigrations\\Version[0-9]{14}$'
work_dir=''
partial_files=''
succeeded=0
dump_pid=''
restore_pids=''
restore_target=''

die() {
    printf 'cadran-backup: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [ -n "$dump_pid" ]; then
        kill "$dump_pid" 2>/dev/null || true
    fi
    for pid in $restore_pids; do
        kill "$pid" 2>/dev/null || true
    done
    # The check database never outlives a run; the new one survives only a finished restore.
    if [ -n "$restore_target" ] && [ -n "$work_dir" ] && { [ "$restore_target" = "$check_database" ] || [ "$succeeded" -ne 1 ]; }; then
        drop_database "$restore_target" >/dev/null 2>&1 || true
    fi
    if [ "$succeeded" -ne 1 ]; then
        for partial in $partial_files; do
            rm -f -- "$partial"
        done
    fi
    if [ -n "$work_dir" ]; then
        rm -rf -- "$work_dir"
    fi
}
# Signals must end in exit so the EXIT cleanup removes partial files.
trap cleanup EXIT
trap 'exit 1' INT TERM HUP

make_work_dir() {
    work_dir=$(mktemp -d /tmp/cadran-backup.XXXXXX)
}

create() {
    label=${1:-}
    case $label in
        manual | daily | pre-upgrade | pre-restore) ;;
        *) die 'label must be one of manual, daily, pre-upgrade, pre-restore' ;;
    esac

    [ -s "$recipient_file" ] || die 'no backup recipient is configured; run make backup-key first'
    [ -s "$password_file" ] || die 'database password is not available'
    [ -d "$backup_dir" ] || die 'backup directory is not mounted'

    recipient=$(tr -d '\r\n' < "$recipient_file")
    printf '%s\n' "$recipient" | grep -Eq '^age1[0-9a-z]+$' || die 'backup recipient is not a valid age public key'

    app_version=${CADRAN_APP_VERSION:-unknown}
    printf '%s\n' "$app_version" | grep -Eq '^[0-9A-Za-z.+-]{1,32}$' || die 'invalid application version'
    database=${CADRAN_DB_NAME:-cadran}
    printf '%s\n' "$database" | grep -Eq '^[a-z0-9_]{1,63}$' || die 'invalid database name'

    make_work_dir
    IFS= read -r password < "$password_file" || [ -n "$password" ]
    escaped=$(printf '%s' "$password" | sed -e 's/\\/\\\\/g' -e 's/:/\\:/g')
    unset password
    printf 'db:5432:%s:cadran:%s\n' "$database" "$escaped" > "$work_dir/pgpass"
    unset escaped
    chmod 600 "$work_dir/pgpass"
    export PGPASSFILE="$work_dir/pgpass"

    started=$(date -u +%s)
    stamp=$(date -u -d "@$started" +%Y%m%d-%H%M%S)
    created_at=$(date -u -d "@$started" +%Y-%m-%dT%H:%M:%SZ)
    base="cadran-$stamp-$label"
    final="$backup_dir/$base.dump.age"
    [ ! -e "$final" ] || die 'a backup with this name already exists'

    partial_dump="$backup_dir/.$base.dump.age.partial"
    partial_sum="$backup_dir/.$base.dump.age.sha256.partial"
    partial_manifest="$backup_dir/.$base.manifest.json.partial"
    partial_files="$partial_dump $partial_sum $partial_manifest"

    schema_query="SELECT CASE WHEN to_regclass('doctrine_migration_versions') IS NULL THEN '' ELSE COALESCE((SELECT max(version) FROM doctrine_migration_versions), '') END"
    schema_version=$(psql --host=db --username=cadran --dbname="$database" --no-psqlrc --tuples-only --no-align --command="$schema_query") ||
        die 'could not read the schema version; backup aborted'
    case $schema_version in
        *'"'*) die 'unexpected schema version' ;;
    esac
    # Doctrine versions are namespaced with backslashes, which JSON requires escaped.
    schema_version=$(printf '%s' "$schema_version" | sed 's/\\/\\\\/g')

    # No pipefail in POSIX sh: go through a FIFO so both exit statuses are visible.
    mkfifo "$work_dir/dump.fifo"
    pg_dump --format=custom --no-owner --no-privileges --host=db --username=cadran "$database" \
        > "$work_dir/dump.fifo" &
    dump_pid=$!
    age_status=0
    age --recipient "$recipient" --output "$partial_dump" < "$work_dir/dump.fifo" || age_status=$?
    dump_status=0
    wait "$dump_pid" || dump_status=$?
    [ "$dump_status" -eq 0 ] || die 'pg_dump failed; backup discarded'
    [ "$age_status" -eq 0 ] || die 'encryption failed; backup discarded'
    [ -s "$partial_dump" ] || die 'backup is empty; discarded'

    digest=$(sha256sum "$partial_dump") || die 'checksum failed; backup discarded'
    digest=${digest%% *}
    size=$(wc -c < "$partial_dump") || die 'could not size the backup'
    size=$(printf '%s' "$size" | tr -d ' ')

    printf '%s  %s\n' "$digest" "$base.dump.age" > "$partial_sum"
    printf '{\n  "format": 1,\n  "createdAt": "%s",\n  "label": "%s",\n  "appVersion": "%s",\n  "schemaVersion": "%s",\n  "postgresVersion": "18",\n  "sizeBytes": %s,\n  "sha256": "%s"\n}\n' \
        "$created_at" "$label" "$app_version" "$schema_version" "$size" "$digest" > "$partial_manifest"
    chmod 600 "$partial_dump" "$partial_sum" "$partial_manifest"

    # The dump is renamed last: a visible dump always has its checksum and manifest.
    mv -- "$partial_sum" "$final.sha256"
    mv -- "$partial_manifest" "$backup_dir/$base.manifest.json"
    mv -- "$partial_dump" "$final"
    succeeded=1

    finished=$(date -u +%s)
    printf '%s %s bytes in %ss\n' "$base.dump.age" "$size" "$((finished - started))"
}

# Retention decision on candidate names read from stdin (sorted, oldest first).
# Prints "keep <name>" or "remove <name>".
retention_plan() {
    awk -v now="$1" '
    function days(y, m, d,    era, yoe, doy, doe) {
        if (m <= 2) y -= 1
        era = int(y / 400)
        yoe = y - era * 400
        doy = int((153 * ((m + 9) % 12) + 2) / 5) + d - 1
        doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + doy
        return era * 146097 + doe - 719468
    }
    function week(day,    dow) {
        dow = ((day + 3) % 7 + 7) % 7
        return (day - dow) / 7
    }
    function civil_month(day,    z, era, doe, yoe, y, doy, mp, m) {
        z = day + 719468
        era = int(z / 146097)
        doe = z - era * 146097
        yoe = int((doe - int(doe / 1460) + int(doe / 36524) - int(doe / 146096)) / 365)
        y = yoe + era * 400
        doy = doe - (365 * yoe + int(yoe / 4) - int(yoe / 100))
        mp = int((5 * doy + 2) / 153)
        m = mp < 10 ? mp + 3 : mp - 9
        if (m <= 2) y += 1
        return y * 12 + m - 1
    }
    function leap(y) { return (y % 4 == 0 && y % 100 != 0) || y % 400 == 0 }
    function valid_date(y, m, d,    limit) {
        if (m < 1 || m > 12 || d < 1) return 0
        limit = mdays[m]
        if (m == 2 && leap(y)) limit = 29
        return d <= limit
    }
    BEGIN {
        split("31 28 31 30 31 30 31 31 30 31 30 31", mdays, " ")
    }
    { n++; name[n] = $0 }
    END {
        today = int(now / 86400)
        current_week = week(today)
        current_month = civil_month(today)
        last = 0
        for (i = 1; i <= n; i++) {
            y = substr(name[i], 8, 4) + 0
            m = substr(name[i], 12, 2) + 0
            d = substr(name[i], 14, 2) + 0
            ok[i] = valid_date(y, m, d)
            if (!ok[i]) continue
            day[i] = days(y, m, d)
            # A name dated in the future cannot come from this clock: leave it alone.
            if (day[i] > today) { ok[i] = 0; continue }
            wk[i] = week(day[i])
            mo[i] = y * 12 + m - 1
            label = substr(name[i], 24)
            sub(/\.dump\.age$/, "", label)
            lab[i] = label
            newest_week[wk[i]] = i
            newest_month[mo[i]] = i
            last = i
        }
        for (i = 1; i <= n; i++) {
            if (!ok[i]) { print "foreign " name[i]; continue }
            age = today - day[i]
            keep = 0
            if (age < 7) keep = 1
            if (wk[i] >= current_week - 3 && newest_week[wk[i]] == i) keep = 1
            if (mo[i] >= current_month - 11 && newest_month[mo[i]] == i) keep = 1
            if ((lab[i] == "pre-upgrade" || lab[i] == "pre-restore") && age < 90) keep = 1
            if (i == last) keep = 1
            print (keep ? "keep " : "remove ") name[i]
        }
    }'
}

prune() {
    dry_run=0
    case ${1:-} in
        --dry-run) dry_run=1 ;;
        '') ;;
        *) die 'usage: prune [--dry-run]' ;;
    esac
    # A forged clock could expire backups early: honour it only for the retention tests.
    now=$(date -u +%s)
    if [ "${CADRAN_BACKUP_ALLOW_TEST_CLOCK:-}" = 1 ] && [ -n "${CADRAN_BACKUP_NOW:-}" ]; then
        now=$CADRAN_BACKUP_NOW
    fi
    printf '%s\n' "$now" | grep -Eq '^[0-9]{1,12}$' || die 'invalid reference time'
    [ -d "$backup_dir" ] || die 'backup directory is not mounted'

    make_work_dir
    candidates="$work_dir/candidates"
    others="$work_dir/others"
    : > "$candidates"
    : > "$others"

    # Only direct children; glob expansion never recurses.
    for path in "$backup_dir"/* "$backup_dir"/.[!.]*; do
        if [ ! -e "$path" ] && [ ! -L "$path" ]; then
            continue
        fi
        entry=${path##*/}
        case $entry in
            *"$newline"*)
                printf 'foreign (name with a line break)\n'
                continue
                ;;
        esac
        if [ -f "$path" ] && [ ! -L "$path" ] && printf '%s\n' "$entry" | grep -Eq "$partial_pattern" &&
            [ -n "$(find "$path" -maxdepth 0 -type f -mmin +1440)" ]; then
            printf 'stale-partial %s\n' "$entry"
            if [ "$dry_run" -eq 0 ]; then
                rm -f -- "$path"
            fi
            continue
        fi
        if [ -f "$path" ] && [ ! -L "$path" ] && printf '%s\n' "$entry" | grep -Eq "$name_pattern"; then
            printf '%s\n' "$entry" >> "$candidates"
        else
            printf '%s\n' "$entry" >> "$others"
        fi
    done
    sort -o "$candidates" "$candidates"

    while IFS= read -r entry; do
        case $entry in
            *.dump.age.sha256) owner=${entry%.sha256} ;;
            *.manifest.json) owner=${entry%.manifest.json}.dump.age ;;
            *) owner='' ;;
        esac
        if [ -n "$owner" ] && grep -qxF -- "$owner" "$candidates"; then
            continue
        fi
        printf 'foreign %s\n' "$(printf '%s' "$entry" | tr -d '\000-\037\177')"
    done < "$others"

    kept=0
    removed=0
    retention_plan "$now" < "$candidates" > "$work_dir/plan"
    while IFS=' ' read -r verdict entry; do
        printf '%s %s\n' "$verdict" "$entry"
        if [ "$verdict" = foreign ]; then
            continue
        fi
        if [ "$verdict" = keep ]; then
            kept=$((kept + 1))
            continue
        fi
        removed=$((removed + 1))
        if [ "$dry_run" -eq 0 ]; then
            # The entry matched name_pattern above, so it holds no slash and no option-like prefix.
            rm -f -- "$backup_dir/$entry" "$backup_dir/$entry.sha256" "$backup_dir/${entry%.dump.age}.manifest.json"
        fi
    done < "$work_dir/plan"
    printf '%s kept, %s removed%s\n' "$kept" "$removed" "$([ "$dry_run" -eq 1 ] && printf ' (dry run)')"
}

# --- Restore (OPS-002) --------------------------------------------------------

admin_psql() {
    psql --host=db --username=cadran --dbname=postgres --no-psqlrc --quiet --tuples-only --no-align \
        --set ON_ERROR_STOP=1 "$@"
}

# Password file shared by every restore subcommand; any database of the cluster.
init_admin() {
    [ -s "$password_file" ] || die 'database password is not available'
    make_work_dir
    IFS= read -r password < "$password_file" || [ -n "$password" ]
    escaped=$(printf '%s' "$password" | sed -e 's/\\/\\\\/g' -e 's/:/\\:/g')
    unset password
    printf 'db:5432:*:cadran:%s\n' "$escaped" > "$work_dir/pgpass"
    unset escaped
    chmod 600 "$work_dir/pgpass"
    export PGPASSFILE="$work_dir/pgpass"
}

# Callers pass a fixed name or one already matched against a strict pattern.
drop_database() {
    admin_psql --command="DROP DATABASE IF EXISTS \"$1\" WITH (FORCE)"
}

require_regular_file() {
    [ -f "$1" ] && [ ! -L "$1" ] || die 'the restore files are not mounted as regular files'
}

# Validates the mounted backup. Sets dump_file and manifest_schema, and
# schema_is_older when the backup predates the running code.
prepare_restore() {
    dump_file=$restore_dir/backup.dump.age
    sidecar_file=$restore_dir/backup.dump.age.sha256
    manifest_file=$restore_dir/backup.manifest.json
    require_regular_file "$dump_file"
    require_regular_file "$sidecar_file"
    require_regular_file "$manifest_file"
    require_regular_file "$identity_file"

    actual=$(sha256sum "$dump_file") || die 'could not compute the checksum'
    actual=${actual%% *}
    sidecar_sum=$(sed -n 's/^\([0-9a-f]\{64\}\)  cadran-[0-9]\{8\}-[0-9]\{6\}-[a-z-]*\.dump\.age$/\1/p' "$sidecar_file" | head -n 1)
    manifest_sum=$(sed -n 's/^  "sha256": "\([0-9a-f]\{64\}\)"$/\1/p' "$manifest_file" | head -n 1)
    if [ -z "$sidecar_sum" ] || [ -z "$manifest_sum" ] || [ "$actual" != "$sidecar_sum" ] || [ "$actual" != "$manifest_sum" ]; then
        die 'checksum mismatch, the file is corrupt or incomplete'
    fi

    manifest_format=$(sed -n 's/^  "format": \([0-9][0-9]*\),$/\1/p' "$manifest_file" | head -n 1)
    [ "$manifest_format" = 1 ] || die 'unknown backup format; restore it with the Cadran version that made it'

    # The manifest escapes the namespace backslash for JSON.
    manifest_schema=$(sed -n 's/^  "schemaVersion": "\(.*\)",$/\1/p' "$manifest_file" | head -n 1 | sed 's/\\\\/\\/g')
    printf '%s\n' "$manifest_schema" | grep -Eq "$schema_pattern" || die 'the manifest schemaVersion is missing or invalid'
    latest_schema=${CADRAN_LATEST_SCHEMA:-}
    printf '%s\n' "$latest_schema" | grep -Eq "$schema_pattern" || die 'could not determine the latest migration of the running code'

    schema_is_older=0
    if [ "$manifest_schema" != "$latest_schema" ]; then
        newest=$(printf '%s\n%s\n' "$manifest_schema" "$latest_schema" | LC_ALL=C sort | tail -n 1)
        if [ "$newest" = "$manifest_schema" ]; then
            die 'incompatible backup: made by a newer Cadran version'
        fi
        schema_is_older=1
    fi
}

# Decrypts the mounted backup into the (re)created target database.
restore_into() {
    target=$1
    restore_target=$target
    drop_database "$target" || die 'could not reset the isolated database'
    admin_psql --command="CREATE DATABASE \"$target\"" || die 'could not create the isolated database'

    # No pipefail in POSIX sh: a FIFO keeps both exit statuses visible.
    mkfifo "$work_dir/plain.fifo"
    age --decrypt --identity "$identity_file" < "$dump_file" > "$work_dir/plain.fifo" 2> "$work_dir/age.err" &
    age_pid=$!
    pg_restore --exit-on-error --single-transaction --no-owner --no-privileges \
        --host=db --username=cadran --dbname="$target" \
        < "$work_dir/plain.fifo" > /dev/null 2> "$work_dir/restore.err" &
    restore_pid=$!
    restore_pids="$age_pid $restore_pid"
    age_status=0
    wait "$age_pid" || age_status=$?
    restore_status=0
    wait "$restore_pid" || restore_status=$?
    restore_pids=''

    if [ "$age_status" -ne 0 ] || [ "$restore_status" -ne 0 ]; then
        # The tools' own messages may quote data: only fixed guidance is printed.
        if grep -q 'No space left on device' "$work_dir/restore.err" "$work_dir/age.err"; then
            die 'the disk is full; free space, then run make db-restore-cleanup to drop old databases and retry'
        fi
        if [ "$age_status" -ne 0 ] && grep -Eqi 'identit' "$work_dir/age.err"; then
            die 'IDENTITY does not match this backup'
        fi
        die 'the backup could not be restored; it may be corrupt or made by an incompatible version'
    fi
}

check_restored() {
    target=$1
    checks_failed=0
    query() {
        psql --host=db --username=cadran --dbname="$target" --no-psqlrc --quiet --tuples-only --no-align \
            --command="$1"
    }

    restored_schema=$(query "SELECT CASE WHEN to_regclass('doctrine_migration_versions') IS NULL THEN '' ELSE COALESCE((SELECT max(version) FROM doctrine_migration_versions), '') END") ||
        die 'verification failed: the restored database cannot be queried'
    if [ "$restored_schema" = "$manifest_schema" ]; then
        printf 'OK     latest migration matches the manifest\n'
    else
        printf 'FAILED latest migration differs from the manifest\n'
        checks_failed=1
    fi

    tables=$(query "SELECT count(*) FROM (VALUES ('identity_users'), ('identity_workspaces'), ('account_financial_accounts'), ('transaction_transactions')) AS wanted(name) WHERE to_regclass(name) IS NOT NULL") ||
        die 'verification failed: the restored database cannot be queried'
    if [ "$tables" = 4 ]; then
        printf 'OK     the four core tables exist\n'
        workspaces=$(query 'SELECT count(*) FROM identity_workspaces') ||
            die 'verification failed: the restored database cannot be queried'
        printf '%s\n' "$workspaces" | grep -Eq '^[0-9]+$' || die 'verification failed: unexpected result'
        if [ "$workspaces" -ge 1 ]; then
            printf 'OK     at least one workspace exists\n'
        else
            printf 'FAILED no workspace in the restored database\n'
            checks_failed=1
        fi
    else
        printf 'FAILED a core table is missing\n'
        checks_failed=1
    fi
    [ "$checks_failed" -eq 0 ] || die 'verification failed; the backup is not usable as is'
}

restore_note() {
    if [ "$schema_is_older" -eq 1 ]; then
        printf 'note: this backup predates the latest migration; run make migrate after restoring\n'
    fi
}

verify() {
    prepare_restore
    init_admin
    restore_into "$check_database"
    check_restored "$check_database"
    restore_note
    printf 'verified: the backup restores into an isolated database and passes the checks\n'
}

restore_new() {
    prepare_restore
    init_admin
    restore_into "$new_database"
    check_restored "$new_database"
    succeeded=1
    printf 'restored into %s\n' "$new_database"
}

live_database() {
    live=${CADRAN_DB_NAME:-cadran}
    printf '%s\n' "$live" | grep -Eq '^[a-z0-9_]{1,63}$' || die 'invalid database name'
}

# Blocks new connections, terminates the existing ones, then renames in one
# transaction; a reconnect between the two steps makes the rename fail and the
# whole sequence is retried. Both databases end up connectable (the renamed
# one stays inspectable and droppable).
rename_databases() {
    # rename_databases <live> <name given to the old live> <replacement>
    # --command does not interpolate psql variables: the statements go through stdin.
    printf '%s\n' 'ALTER DATABASE :"live" WITH ALLOW_CONNECTIONS false;' |
        admin_psql --set "live=$1" --file - > /dev/null 2>&1 || return 1
    attempt=1
    while [ "$attempt" -le 3 ]; do
        if printf '%s\n' \
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :'live' AND pid <> pg_backend_pid();" \
            'ALTER DATABASE :"live" RENAME TO :"retired";' \
            'ALTER DATABASE :"replacement" RENAME TO :"live";' \
            'ALTER DATABASE :"live" WITH ALLOW_CONNECTIONS true;' \
            'ALTER DATABASE :"retired" WITH ALLOW_CONNECTIONS true;' |
            admin_psql --single-transaction --set "live=$1" --set "retired=$2" --set "replacement=$3" \
                --file - > /dev/null 2>&1; then
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done
    # Nothing was renamed: let the application reach the live database again.
    printf '%s\n' 'ALTER DATABASE :"live" WITH ALLOW_CONNECTIONS true;' |
        admin_psql --set "live=$1" --file - > /dev/null 2>&1 || true
    return 1
}

swap() {
    live_database
    init_admin
    stamp=$(date -u +%Y%m%d%H%M%S)
    rename_databases "$live" "cadran_previous_$stamp" "$new_database" ||
        die 'could not replace the live database; it is unchanged'
    printf 'previous=cadran_previous_%s\nfailed=cadran_failed_%s\n' "$stamp" "$stamp"
}

rollback() {
    previous=${1:-}
    failed=${2:-}
    printf '%s\n' "$previous" | grep -Eq "$previous_pattern" || die 'invalid previous database name'
    printf '%s\n' "$failed" | grep -Eq "$failed_pattern" || die 'invalid failed database name'
    live_database
    init_admin
    rename_databases "$live" "$failed" "$previous" || die "could not roll back; rename $previous to $live by hand"
    printf 'rolled back to %s; the failed database is kept as %s\n' "$previous" "$failed"
}

drop_new() {
    init_admin
    drop_database "$new_database"
}

previous_databases() {
    admin_psql --command="SELECT datname FROM pg_database WHERE datname ~ '^cadran_previous_[0-9]{14}\$' ORDER BY datname" |
        grep -E "$previous_pattern" || true
}

list_previous() {
    init_admin
    previous_databases
}

drop_previous() {
    init_admin
    for name in $(previous_databases); do
        printf '%s\n' "$name" | grep -Eq "$previous_pattern" || continue
        drop_database "$name"
        printf 'dropped %s\n' "$name"
    done
}

command=${1:-}
[ "$#" -eq 0 ] || shift
case $command in
    create) create "$@" ;;
    prune) prune "$@" ;;
    keygen) exec age-keygen ;;
    verify) verify ;;
    restore-new) restore_new ;;
    swap) swap ;;
    rollback) rollback "$@" ;;
    drop-new) drop_new ;;
    list-previous) list_previous ;;
    drop-previous) drop_previous ;;
    *) die 'usage: cadran-backup create <label> | prune [--dry-run] | keygen | verify | restore-new | swap | rollback | drop-new | list-previous | drop-previous' ;;
esac
