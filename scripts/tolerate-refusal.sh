#!/bin/sh
# Run a command and accept one specific, expected refusal.
#   tolerate-refusal.sh '<message fragment>' <command> [args...]
# The command's output is shown. A non-zero exit passes only when the output holds the
# fragment, whitespace-normalised because console error boxes wrap (e.g. "already provisioned" on a re-run); any other failure is propagated.
set -u
fragment=$1
shift
output=$("$@" 2>&1)
status=$?
printf '%s\n' "$output"
if [ "$status" -ne 0 ] && printf '%s' "$output" | tr -s ' \n' '  ' | grep -qF "$fragment"; then
    echo "Tolerated expected refusal: $fragment"
    exit 0
fi
exit "$status"
