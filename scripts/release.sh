#!/usr/bin/env bash
set -euo pipefail

script_dir=$(CDPATH= cd "$(dirname "$0")" && pwd)
project_dir=$(CDPATH= cd "$script_dir/.." && pwd)
ci_timeout_seconds=1800
# Only the root package carries the product version; workspace packages stay at 0.0.0.
package_files=(package.json)
# Every PR needs a milestone and labels; override per release if the milestone differs.
release_milestone=${RELEASE_MILESTONE:-v1.0.0}
release_labels=${RELEASE_LABELS:-enhancement,area:quality}

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

usage() {
  printf '%s\n' "Usage: $0 <MAJOR.MINOR.PATCH>"
}

normalize_version() {
  printf '%s\n' "${1#v}"
}

validate_version() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || \
    fail "Version must use the MAJOR.MINOR.PATCH format."
}

ensure_clean_worktree() {
  [[ -z "$(git status --porcelain)" ]] || fail "Working tree must be clean."
}

read_package_version() {
  node - "${package_files[@]}" <<'NODE'
const fs = require('node:fs')

const files = process.argv.slice(2)
const packages = files.map((file) => JSON.parse(fs.readFileSync(file, 'utf8')))
const version = packages[0].version

if (packages.some((pkg) => pkg.version !== version)) {
  process.stderr.write('Package versions are not aligned.\n')
  process.exit(1)
}

process.stdout.write(version)
NODE
}

compare_versions() {
  node - "$1" "$2" <<'NODE'
const [current, requested] = process.argv.slice(2)
const currentParts = current.split('.').map(Number)
const requestedParts = requested.split('.').map(Number)

for (let index = 0; index < currentParts.length; index += 1) {
  if (requestedParts[index] > currentParts[index]) {
    process.stdout.write('newer')
    process.exit(0)
  }
  if (requestedParts[index] < currentParts[index]) {
    process.stdout.write('older')
    process.exit(0)
  }
}

process.stdout.write('same')
NODE
}

ensure_tag_available() {
  if git rev-parse --verify --quiet "refs/tags/v$1" >/dev/null; then
    fail "Local tag v$1 already exists."
  fi

  if git ls-remote --exit-code --tags origin "refs/tags/v$1" >/dev/null 2>&1; then
    fail "Remote tag v$1 already exists."
  fi
}

ensure_branch_available() {
  if git show-ref --verify --quiet "refs/heads/$1"; then
    fail "Local branch $1 already exists."
  fi

  if git ls-remote --exit-code --heads origin "refs/heads/$1" >/dev/null 2>&1; then
    fail "Remote branch $1 already exists."
  fi
}

# Turn the Unreleased section into the release entry and reopen an empty Unreleased one.
# Contributors already add their line to Unreleased in each pull request.
finalize_changelog() {
  node - "$1" <<'NODE'
const fs = require('node:fs')

const version = process.argv[2]
const file = 'CHANGELOG.md'
const changelog = fs.readFileSync(file, 'utf8')
const heading = '## [Unreleased]'
const start = changelog.indexOf(heading)

if (start === -1) {
  process.stderr.write('CHANGELOG.md has no Unreleased section.\n')
  process.exit(1)
}

const body = changelog.slice(start + heading.length)
const next = body.search(/^## \[/m)
const content = next === -1 ? body : body.slice(0, next)

if (!/^- /m.test(content)) {
  process.stderr.write('The Unreleased section of CHANGELOG.md has no entry.\n')
  process.exit(1)
}

const date = new Date().toISOString().slice(0, 10)
fs.writeFileSync(
  file,
  changelog.replace(heading, `${heading}\n\n## [${version}] - ${date}`),
)
NODE
}

write_package_versions() {
  node - "$1" "${package_files[@]}" <<'NODE'
const fs = require('node:fs')

const [version, ...files] = process.argv.slice(2)

for (const file of files) {
  const pkg = JSON.parse(fs.readFileSync(file, 'utf8'))
  pkg.version = version
  fs.writeFileSync(file, `${JSON.stringify(pkg, null, 2)}\n`)
}
NODE
}

wait_for_pr_merge() {
  local pr_url="$1"
  local deadline=$((SECONDS + ci_timeout_seconds))
  local state

  while ((SECONDS < deadline)); do
    state=$(gh pr view "$pr_url" --json state --jq .state)
    case "$state" in
      MERGED)
        return
        ;;
      CLOSED)
        fail "Release pull request was closed without being merged."
        ;;
    esac
    sleep 10
  done

  fail "Timed out waiting for the release pull request to merge."
}

wait_for_pr_checks() {
  local pr_url="$1"
  local deadline=$((SECONDS + ci_timeout_seconds))
  local check_count

  while ((SECONDS < deadline)); do
    check_count=$(gh pr view "$pr_url" --json statusCheckRollup --jq '.statusCheckRollup | length')
    if [[ "$check_count" -gt 0 ]]; then
      gh pr checks "$pr_url" --watch --fail-fast --interval 10
      return
    fi
    sleep 5
  done

  fail "Timed out waiting for pull request checks to start."
}

wait_for_ci_gate() {
  local repository="$1"
  local commit_sha="$2"
  local deadline=$((SECONDS + ci_timeout_seconds))
  local result status conclusion

  printf '%s\n' "Waiting for CI gate on $commit_sha..."

  while ((SECONDS < deadline)); do
    result=$(gh api "repos/$repository/commits/$commit_sha/check-runs" --jq \
      '([.check_runs[] | select(.name == "CI gate")] | sort_by(.completed_at) | last) as $run | if $run == null then ["missing", ""] else [$run.status, ($run.conclusion // "")] end | @tsv')
    IFS=$'\t' read -r status conclusion <<<"$result"

    if [[ "$status" == "completed" ]]; then
      [[ "$conclusion" == "success" ]] || fail "CI gate completed with $conclusion."
      return
    fi

    sleep 10
  done

  fail "Timed out waiting for CI gate."
}

create_release_pr() {
  local version="$1"
  local release_branch="chore/release-v$version"
  local commit_title="chore: prepare the v$version release"
  local release_commit pr_url

  ensure_branch_available "$release_branch"
  git switch -c "$release_branch"
  write_package_versions "$version"
  finalize_changelog "$version"
  git add -- "${package_files[@]}" CHANGELOG.md
  git commit -m "$commit_title"
  release_commit=$(git rev-parse HEAD)
  git push --set-upstream origin "$release_branch"

  pr_url=$(gh pr create \
    --base main \
    --head "$release_branch" \
    --title "$commit_title" \
    --milestone "$release_milestone" \
    --body "$(printf '%s\n' \
      '## Summary' \
      "Prepare the v$version release by bumping the package version and dating the changelog entry." \
      '' \
      '## Type of change' \
      '- [x] `chore` / `ci` / `docs` / `test`' \
      '' \
      '## Checklist' \
      '- [x] Version-only change generated by the release script' \
      '- [x] This PR targets `main` from an up-to-date release branch' \
      '' \
      '## How to test' \
      '- Confirm package.json has the requested version and CHANGELOG.md the dated release entry.' \
      '' \
      '## Risks and rollback' \
      '- Low risk; close the PR without merging to cancel the release.')")

  gh pr edit "$pr_url" --add-label "$release_labels"

  wait_for_pr_checks "$pr_url"
  gh pr merge "$pr_url" --squash --delete-branch --auto --match-head-commit "$release_commit"
  wait_for_pr_merge "$pr_url"

  git switch main
  git pull --ff-only origin main
}

run_release() {
  [[ $# -eq 1 ]] || {
    usage >&2
    exit 1
  }

  local version current_version version_relation branch repository owner actor main_commit answer
  version=$(normalize_version "$1")
  validate_version "$version"
  ensure_clean_worktree

  branch=$(git branch --show-current)
  [[ "$branch" == "main" ]] || fail "Release must be started from main."

  gh auth status --hostname github.com >/dev/null 2>&1 || fail "GitHub CLI authentication is required."
  repository=$(gh repo view --json nameWithOwner --jq .nameWithOwner)
  owner=${repository%%/*}
  actor=$(gh api user --jq .login)
  [[ "${actor,,}" == "${owner,,}" ]] || fail "Release must be run by the repository owner."

  git fetch origin main --quiet
  [[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || \
    fail "Local main must exactly match origin/main. Run git pull --ff-only first."

  ensure_tag_available "$version"
  current_version=$(read_package_version)
  version_relation=$(compare_versions "$current_version" "$version")

  [[ "$version_relation" != "older" ]] || fail "Version $version is older than $current_version."

  printf '%s' "Release v$version as $actor in $repository? [y/N] "
  read -r answer
  [[ "${answer,,}" == "y" || "${answer,,}" == "yes" ]] || fail "Release cancelled."

  case "$version_relation" in
    newer)
      create_release_pr "$version"
      ;;
    same)
      printf '%s\n' "Package version is already $version; resuming from the tag step."
      ;;
  esac

  ensure_clean_worktree
  [[ "$(git branch --show-current)" == "main" ]] || fail "Release did not return to main."
  [[ "$(read_package_version)" == "$version" ]] || fail "Merged package version does not match $version."
  [[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || \
    fail "Merged release commit is not synchronized with origin/main."

  main_commit=$(git rev-parse HEAD)
  wait_for_ci_gate "$repository" "$main_commit"
  git tag -s "v$version" -m "cadran v$version"
  git push origin "v$version"

  printf '%s\n' "Release tag v$version was pushed successfully."
}

command -v git >/dev/null 2>&1 || fail "git is required."
command -v node >/dev/null 2>&1 || fail "Node.js is required."
command -v gh >/dev/null 2>&1 || fail "GitHub CLI is required."

cd "$project_dir"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "Project is not a Git worktree."
run_release "$@"
