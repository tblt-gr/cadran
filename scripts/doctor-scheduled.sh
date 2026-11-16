#!/bin/sh
# Daily run of make doctor from cron: keeps its exit status and, on exit 1, appends one line to
# var/log/doctor.log and raises a desktop notification. Logs and notifies check names only.
set -u

cd -- "$(dirname -- "$0")/.." || exit 1
powershell=${CADRAN_POWERSHELL:-/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe}
title='Cadran: make doctor failed'

report=$(sh scripts/doctor.sh 2> /dev/null)
status=$?
printf '%s\n' "$report"
[ "$status" -eq 1 ] || exit "$status"

# Check names are the text between "FAIL " and the first colon. Anything outside a conservative
# character set is dropped, so the names are safe in a log line and in a PowerShell argument.
names=$(printf '%s\n' "$report" | sed -n 's/^FAIL \([^:]*\):.*/\1/p' | tr -cd 'A-Za-z0-9 _.\n-' | paste -sd, -)
[ -n "$names" ] || names=doctor

umask 077
log_dir=var/log
mkdir -p "$log_dir" && chmod 700 "$log_dir"
printf '%s FAIL %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$names" >> "$log_dir/doctor.log"
chmod 600 "$log_dir/doctor.log"

# The script text is fixed; the names reach it only as an argument.
toast=$(cat <<'PS'
$x = [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]::GetTemplateContent([Windows.UI.Notifications.ToastTemplateType]::ToastText02);
$t = $x.GetElementsByTagName('text');
$t.Item(0).InnerText = 'Cadran: make doctor failed';
$t.Item(1).InnerText = $args[0];
$n = [Windows.UI.Notifications.ToastNotification, Windows.UI.Notifications, ContentType = WindowsRuntime]::new($x);
[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]::CreateToastNotifier('{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe').Show($n)
PS
)

if [ -x "$powershell" ]; then
    "$powershell" -NoProfile -NonInteractive -Command "& { $toast } '$names'" > /dev/null 2>&1 || true
elif command -v notify-send > /dev/null 2>&1; then
    notify-send "$title" "$names" > /dev/null 2>&1 || true
fi

exit 1
