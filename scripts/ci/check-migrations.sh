#!/bin/sh
# Exercises the two migration paths required by the Definition of Done:
#   1. a fresh, empty database migrated to head;
#   2. the schema already released on the base branch, then this branch's
#      additional migrations applied on top of it;
#   3. (OPS-003) the latest release tag, seeded through its own console, then
#      this branch migrated on top: row counts unchanged and the application boots.
#
# Expects DATABASE_URL to point at a disposable PostgreSQL instance and
# MIGRATION_BASE_REF (default origin/main) to resolve to the comparison point.
set -eu

# apps/api/.env is gitignored and absent in CI; keep the Symfony runtime from
# trying to read it (matches the app container entrypoint). Avoid a parameter
# expansion with a JSON object as its default: the closing brace is parsed as
# part of the shell expansion and corrupts an already supplied value.
if [ -z "${APP_RUNTIME_OPTIONS:-}" ]; then
    export APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
fi

console="php apps/api/bin/console"
base_ref="${MIGRATION_BASE_REF:-origin/main}"

reset_database() {
    $console doctrine:database:drop --force --if-exists --no-interaction
    $console doctrine:database:create --no-interaction
}

migration_files=$(find apps/api/migrations -name 'Version*.php' 2>/dev/null | wc -l || true)

echo "== Empty-database migration =="
reset_database
$console doctrine:migrations:sync-metadata-storage --no-interaction
$console doctrine:migrations:migrate --no-interaction --allow-no-migration
$console doctrine:migrations:up-to-date --no-interaction

# Guard against a misconfigured migrations_paths: after a successful empty-DB
# migrate, Doctrine must know about exactly the files on disk. Count registered
# migration identifiers (VersionYYYYMMDDHHMMSS), not the word "Version" which
# also appears in the command's table header.
registered=$($console doctrine:migrations:list --no-interaction 2>/dev/null \
    | grep -cE 'Version[0-9]{14}' || true)
if [ "$migration_files" -ne "$registered" ]; then
    echo "Found $migration_files migration file(s) but Doctrine registered $registered." >&2
    exit 1
fi

# A forward-only check misses teardown errors such as a function left behind by
# down(). Exercise the latest migration in both directions on the disposable
# database, then verify that the schema is back at head.
latest_file=$(find apps/api/migrations -name 'Version*.php' | sort | tail -n 1)
latest_version=$(basename "$latest_file" .php)
latest_migration="DoctrineMigrations\\$latest_version"
echo "== Latest-migration down/up cycle: $latest_version =="
$console doctrine:migrations:execute "$latest_migration" --down --no-interaction
$console doctrine:migrations:execute "$latest_migration" --up --no-interaction
$console doctrine:migrations:up-to-date --no-interaction

# Release-tag upgrade: what a real installation does when it moves to this
# version. The tag runs its own code (its own composer install, its own owner
# setup and demo seed); only table names and row counts are ever printed.
echo "== Upgrade from the latest release tag =="
release_tag=$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-version:refname | head -n 1)
if [ -z "$release_tag" ]; then
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
        echo "No release tag is available in this checkout (fetch the tags)." >&2
        exit 1
    fi
    echo "== Release-tag upgrade skipped: no release tag in this clone =="
else
    scenario="php scripts/ci/upgrade-scenario.php"
    release_tree="$(mktemp -d)"
    counts_before="$(mktemp)"
    counts_after="$(mktemp)"
    release_cleanup() {
        git worktree remove --force "$release_tree" 2>/dev/null || rm -rf "$release_tree"
        rm -f "$counts_before" "$counts_after"
    }
    trap release_cleanup EXIT
    git worktree add --detach "$release_tree" "$release_tag" >/dev/null

    reset_database
    (
        cd "$release_tree/apps/api"
        composer install --no-interaction --no-progress --prefer-dist --no-scripts --quiet
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    )
    $scenario provision "$release_tree/apps/api"
    (cd "$release_tree/apps/api" && php -d memory_limit=512M bin/console --no-debug app:demo:seed --no-interaction >/dev/null)
    $scenario counts "$release_tree/apps/api" > "$counts_before"

    seeded=$(awk '$1 != "doctrine_migration_versions" && $2 > 0 { n++ } END { print n + 0 }' "$counts_before")
    if [ "$seeded" -lt 3 ]; then
        echo "The demo seed of $release_tag filled only $seeded table(s): the upgrade check would prove nothing." >&2
        exit 1
    fi

    $console doctrine:migrations:migrate --no-interaction --allow-no-migration
    $console doctrine:migrations:up-to-date --no-interaction
    $scenario counts apps/api > "$counts_after"

    # Tables the branch dropped or renamed on purpose are not comparable.
    changed=$(awk 'NR == FNR { after[$1] = $2; next } ($1 in after) && $1 != "doctrine_migration_versions" && after[$1] != $2 { print $1 }' \
        "$counts_after" "$counts_before")
    if [ -n "$changed" ]; then
        echo "Row counts changed by the upgrade from $release_tag in: $changed" >&2
        exit 1
    fi
    $console about --no-interaction >/dev/null
    $console lint:container --no-interaction
    echo "Upgrade from $release_tag: $seeded seeded table(s) kept their row counts and the application boots."
    release_cleanup
    trap - EXIT
fi

if ! git rev-parse --verify --quiet "$base_ref" >/dev/null; then
    if [ -n "${GITHUB_BASE_REF:-}" ]; then
        echo "Base ref $base_ref is required on a pull request but was not found." >&2
        exit 1
    fi
    echo "== Upgrade path skipped: $base_ref is not available (not a pull request) =="
    exit 0
fi

if git diff --quiet "$base_ref" -- apps/api/migrations; then
    echo "== Upgrade path skipped: no migration change against $base_ref =="
    exit 0
fi

echo "== Upgrade path: apply $base_ref migrations, then this branch on top =="
worktree="$(mktemp -d)"
cleanup() { git worktree remove --force "$worktree" 2>/dev/null || rm -rf "$worktree"; }
trap cleanup EXIT
git worktree add --detach "$worktree" "$base_ref" >/dev/null

reset_database
(
    cd "$worktree/apps/api"
    composer install --no-interaction --no-progress --prefer-dist --no-scripts --quiet
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
)
$console doctrine:migrations:migrate --no-interaction --allow-no-migration
$console doctrine:migrations:up-to-date --no-interaction
