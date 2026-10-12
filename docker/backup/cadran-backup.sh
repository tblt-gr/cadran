#!/bin/sh
# One-shot backup tool of the cadran_backup image. Subcommands:
#   create <label>       encrypted pg_dump of the database into /backups
#   prune [--dry-run]    apply the retention policy inside /backups
#   keygen               print a new age identity (private key) on stdout
#
# Never prints dump content, the database password or the recipient.
set -eu
umask 077

backup_dir=/backups
password_file=/run/db_password
recipient_file=/run/backup_recipient
name_pattern='^cadran-[0-9]{8}-[0-9]{6}-(manual|daily|pre-upgrade|pre-restore)\.dump\.age$'
partial_pattern='^\.cadran-[0-9]{8}-[0-9]{6}-(manual|daily|pre-upgrade|pre-restore)\.(dump\.age|dump\.age\.sha256|manifest\.json)\.partial$'
newline='
'
work_dir=''
partial_files=''
succeeded=0
dump_pid=''

die() {
    printf 'cadran-backup: %s\n' "$1" >&2
    exit 1
}

cleanup() {
    if [ -n "$dump_pid" ]; then
        kill "$dump_pid" 2>/dev/null || true
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

command=${1:-}
[ "$#" -eq 0 ] || shift
case $command in
    create) create "$@" ;;
    prune) prune "$@" ;;
    keygen) exec age-keygen ;;
    *) die 'usage: cadran-backup create <label> | prune [--dry-run] | keygen' ;;
esac
