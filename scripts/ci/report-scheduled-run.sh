#!/bin/sh
# Open, update or close the issue that tracks a failing scheduled workflow (OPS-009).
# Usage: report-scheduled-run.sh <workflow-title> <success|failure>
# Environment: GH_TOKEN, GITHUB_REPOSITORY, GITHUB_SERVER_URL, GITHUB_RUN_ID (provided by
# Actions) and optionally FAILED_JOBS, a space-separated list of job names. Only the run URL, the
# date and job names are written: never a log excerpt or a finding.
set -eu

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <workflow-title> <success|failure>" >&2
  exit 2
fi
title=$1
result=$2
case "$result" in
  success | failure) ;;
  *)
    echo "result must be success or failure" >&2
    exit 2
    ;;
esac

issue_title="Scheduled run failing: $title"
run_url="$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID"
today=$(date -u +%Y-%m-%d)

# The search is fuzzy: keep only the exact title.
number=$(gh issue list --state open --search "in:title \"$issue_title\"" --json number,title \
  --jq "[.[] | select(.title == \"$issue_title\")][0].number // empty")

if [ "$result" = failure ]; then
  jobs=${FAILED_JOBS:-}
  if [ -n "$number" ]; then
    gh issue comment "$number" --body "Failed again on $today: $run_url"
  else
    body="The scheduled workflow \"$title\" failed on $today.

Run: $run_url"
    [ -z "$jobs" ] || body="$body
Failed jobs: $jobs"
    gh issue create --title "$issue_title" --label bug --label area:quality --body "$body"
  fi
elif [ -n "$number" ]; then
  gh issue comment "$number" --body "Recovered in $run_url"
  gh issue close "$number"
fi
