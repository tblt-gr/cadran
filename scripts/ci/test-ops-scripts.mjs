// Infrastructure tests for scripts/install-cron.sh and `backup.sh health --strict` (OPS-006), and
// scripts/ci/report-scheduled-run.sh (OPS-009), and the migration check of scripts/doctor.sh (OPS-010).
// No Docker, no real crontab: a fake `crontab` on PATH stores the table in a file.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
  copyFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  statSync,
  symlinkSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const scripts = fileURLToPath(new URL('..', import.meta.url));
const base = mkdtempSync(join(tmpdir(), 'cadran-ops-'));

const makeRepo = (name) => {
  const root = join(base, name);
  mkdirSync(join(root, 'scripts'), { recursive: true });
  copyFileSync(join(scripts, 'install-cron.sh'), join(root, 'scripts', 'install-cron.sh'));
  copyFileSync(join(scripts, 'backup.sh'), join(root, 'scripts', 'backup.sh'));
  return root;
};

try {
  // Fake crontab: `crontab -l` prints the table (exit 1 when empty), `crontab -` replaces it.
  const bin = join(base, 'bin');
  const table = join(base, 'table');
  mkdirSync(bin);
  writeFileSync(
    join(bin, 'crontab'),
    `#!/bin/sh\ncase "$1" in\n  -l) [ -s "${table}" ] || { echo 'no crontab for user' >&2; exit 1; }; cat "${table}" ;;\n  -) cat > "${table}" ;;\n  *) exit 2 ;;\nesac\n`,
    { mode: 0o755 },
  );
  const env = { ...process.env, PATH: `${bin}:${process.env.PATH}` };
  const run = (root, ...args) =>
    spawnSync('sh', [join(root, 'scripts', 'install-cron.sh'), ...args], { encoding: 'utf8', env });
  const readTable = () => {
    try {
      return readFileSync(table, 'utf8');
    } catch {
      return '';
    }
  };

  const repo = makeRepo('repo');
  const unrelated = '0 1 * * * /usr/bin/true # keep me\n';
  writeFileSync(table, unrelated);

  const dry = run(repo, '--dry-run');
  assert.equal(dry.status, 0);
  assert.equal(readTable(), unrelated, '--dry-run must not change the crontab');
  assert.match(dry.stdout, /^# BEGIN cadran/);
  assert.match(dry.stdout, /# END cadran\n$/);
  for (const target of [
    'recurrences-materialize',
    'db-backup LABEL=daily',
    'db-backup-prune',
    'idempotency-purge',
  ]) {
    assert.ok(dry.stdout.includes(`cd '${repo}' && make ${target} >/dev/null 2>&1`), target);
  }

  assert.ok(
    dry.stdout.includes(
      `15 6 * * * cd '${repo}' && sh scripts/doctor-scheduled.sh >/dev/null 2>&1\n`,
    ),
    'daily doctor',
  );

  assert.equal(run(repo).status, 0);
  const installed = readTable();
  assert.ok(installed.startsWith(unrelated), 'other lines stay untouched');
  assert.equal(installed.split('# BEGIN cadran').length - 1, 1);
  assert.equal(run(repo).status, 0);
  assert.equal(readTable(), installed, 'a second run gives the same crontab');

  assert.equal(run(repo, '--remove').status, 0);
  assert.equal(readTable(), unrelated, '--remove deletes the block only');

  assert.equal(run(repo, '--bogus').status, 2);
  assert.equal(run(repo, '--dry-run', '--remove').status, 2);

  for (const unsafe of ['per%cent', "quo'te"]) {
    const bad = makeRepo(unsafe);
    const result = run(bad);
    assert.equal(result.status, 1, `${unsafe} must be refused`);
    assert.equal(readTable(), unrelated);
  }

  // backup.sh health --strict: warnings fail only with the flag.
  const backups = join(base, 'backups');
  mkdirSync(backups, { mode: 0o700 });
  const health = (...args) =>
    spawnSync('sh', [join(repo, 'scripts', 'backup.sh'), 'health', ...args], {
      encoding: 'utf8',
      env: { ...process.env, CADRAN_BACKUP_DIR: backups },
    });
  const addBackup = (stamp, createdAt) => {
    const stem = join(backups, `cadran-${stamp}-daily`);
    writeFileSync(`${stem}.dump.age`, 'x');
    writeFileSync(
      `${stem}.manifest.json`,
      `{\n  "createdAt": "${createdAt}",\n  "label": "daily",\n  "v": 1\n}\n`,
    );
  };

  assert.equal(health().status, 0);
  assert.equal(health('--strict').status, 1, 'no backup');
  addBackup('20200101-000000', '2020-01-01T00:00:00Z');
  assert.equal(health().status, 0);
  assert.equal(health('--strict').status, 1, 'older than 48 hours');
  addBackup('29990101-000000', new Date().toISOString().replace(/\.\d+Z$/, 'Z'));
  assert.equal(health('--strict').status, 0, 'fresh backup');
  assert.equal(health('--bogus').status, 1);

  // report-scheduled-run.sh: a stubbed `gh` logs each call and answers `issue list` from a file.
  const ghLog = join(base, 'gh.log');
  const ghList = join(base, 'gh-list');
  writeFileSync(
    join(bin, 'gh'),
    `#!/bin/sh\nprintf '%s\\036' "$*" >> "${ghLog}"\nif [ "$2" = list ]; then cat "${ghList}"; fi\n`,
    { mode: 0o755 },
  );
  const report = (result, existing, extraEnv = {}) => {
    writeFileSync(ghLog, '');
    writeFileSync(ghList, existing);
    const out = spawnSync(
      'sh',
      [join(scripts, 'ci', 'report-scheduled-run.sh'), 'Image scan (nightly)', result],
      {
        encoding: 'utf8',
        env: {
          ...env,
          GITHUB_SERVER_URL: 'https://github.com',
          GITHUB_REPOSITORY: 'o/r',
          GITHUB_RUN_ID: '42',
          ...extraEnv,
        },
      },
    );
    return { status: out.status, calls: readFileSync(ghLog, 'utf8').split('\x1e').slice(1, -1) };
  };
  const issueTitle = 'Scheduled run failing: Image scan (nightly)';
  const runUrl = 'https://github.com/o/r/actions/runs/42';
  const today = new Date().toISOString().slice(0, 10);
  // The stub ignores --jq, so it prints what the real command would after filtering.
  const found = '7\n';

  const opened = report('failure', '', { FAILED_JOBS: 'image-scan-strict secrets' });
  assert.equal(opened.status, 0);
  assert.deepEqual(opened.calls, [
    `issue create --title ${issueTitle} --label bug --label area:quality --body The scheduled workflow "Image scan (nightly)" failed on ${today}.\n\nRun: ${runUrl}\nFailed jobs: image-scan-strict secrets`,
  ]);

  const again = report('failure', found);
  assert.deepEqual(again.calls, [`issue comment 7 --body Failed again on ${today}: ${runUrl}`]);

  const recovered = report('success', found);
  assert.deepEqual(recovered.calls, [
    `issue comment 7 --body Recovered in ${runUrl}`,
    'issue close 7',
  ]);

  assert.deepEqual(report('success', '').calls, [], 'nothing to close');
  assert.equal(
    spawnSync('sh', [join(scripts, 'ci', 'report-scheduled-run.sh'), 'x', 'maybe'], { env }).status,
    2,
  );

  // doctor.sh: a stubbed `docker` logs each call. The migration check must run the console through
  // `compose run --rm --no-deps app` (entrypoint), never `compose exec` (no runtime env).
  const dockerLog = join(base, 'docker.log');
  writeFileSync(
    join(bin, 'docker'),
    `#!/bin/sh
printf '%s\\n' "$*" >> "${dockerLog}"
case "$*" in
  *" run "*bin/console*) [ -z "$DOCTOR_MIGRATIONS_PENDING" ] ;;
  *" exec "*bin/console*) echo 'Unable to read the ".env" environment file' >&2; exit 1 ;;
esac
`,
    { mode: 0o755 },
  );
  copyFileSync(join(scripts, 'doctor.sh'), join(repo, 'scripts', 'doctor.sh'));
  const doctor = (extraEnv = {}) => {
    writeFileSync(dockerLog, '');
    const out = spawnSync('sh', [join(repo, 'scripts', 'doctor.sh')], {
      encoding: 'utf8',
      env: { ...env, CADRAN_BACKUP_DIR: backups, ...extraEnv },
    });
    return { lines: out.stdout.split('\n'), calls: readFileSync(dockerLog, 'utf8').split('\n') };
  };

  const upToDate = doctor();
  assert.ok(upToDate.lines.includes('OK migrations'));
  const consoleCalls = upToDate.calls.filter((call) => call.includes('bin/console'));
  assert.equal(consoleCalls.length, 1);
  assert.match(
    consoleCalls[0],
    / run --rm --no-deps app php apps\/api\/bin\/console doctrine:migrations:up-to-date/,
  );
  assert.ok(!upToDate.calls.some((call) => / exec /.test(call) && call.includes('bin/console')));

  const pending = doctor({ DOCTOR_MIGRATIONS_PENDING: '1' });
  assert.ok(pending.lines.some((line) => line.startsWith('FAIL migrations: ')));

  // doctor-scheduled.sh: stubbed doctor.sh, powershell.exe and notify-send log their arguments.
  const sched = join(base, 'sched');
  mkdirSync(join(sched, 'scripts'), { recursive: true });
  copyFileSync(join(scripts, 'doctor-scheduled.sh'), join(sched, 'scripts', 'doctor-scheduled.sh'));
  const notifyLog = join(base, 'notify.log');
  const stubTools = (dir, names) => {
    mkdirSync(dir, { recursive: true });
    for (const name of names) {
      writeFileSync(join(dir, name), `#!/bin/sh\nprintf '%s\\036' "$0|$*" >> "${notifyLog}"\n`, {
        mode: 0o755,
      });
    }
  };
  const plainBin = join(base, 'plain-bin');
  mkdirSync(plainBin);
  for (const tool of ['sh', 'date', 'mkdir', 'chmod', 'sed', 'tr', 'paste', 'cat', 'dirname']) {
    const found = spawnSync('sh', ['-c', `command -v ${tool}`], { encoding: 'utf8' }).stdout.trim();
    symlinkSync(found, join(plainBin, tool));
  }
  const withNotify = join(base, 'notify-bin');
  stubTools(withNotify, ['notify-send']);
  symlinkSync(join(withNotify, 'notify-send'), join(plainBin, 'notify-send'));
  const fakePowershell = join(base, 'powershell.exe');
  stubTools(base, ['powershell.exe']);

  const scheduled = (doctorOutput, doctorStatus, { powershell, notifySend }) => {
    writeFileSync(notifyLog, '');
    rmSync(join(sched, 'var'), { recursive: true, force: true });
    writeFileSync(
      join(sched, 'scripts', 'doctor.sh'),
      `#!/bin/sh\ncat <<'OUT'\n${doctorOutput}OUT\nexit ${doctorStatus}\n`,
    );
    const dir = join(base, `path-${notifySend}`);
    mkdirSync(dir, { recursive: true });
    for (const tool of ['sh', 'date', 'mkdir', 'chmod', 'sed', 'tr', 'paste', 'cat', 'dirname']) {
      try {
        symlinkSync(join(plainBin, tool), join(dir, tool));
      } catch {
        // already linked by a previous call
      }
    }
    if (notifySend) {
      try {
        symlinkSync(join(withNotify, 'notify-send'), join(dir, 'notify-send'));
      } catch {
        // already linked
      }
    }
    const out = spawnSync('sh', [join(sched, 'scripts', 'doctor-scheduled.sh')], {
      encoding: 'utf8',
      env: { PATH: dir, CADRAN_POWERSHELL: powershell ? fakePowershell : join(base, 'absent.exe') },
    });
    const logFile = join(sched, 'var', 'log', 'doctor.log');
    let log = null;
    try {
      log = readFileSync(logFile, 'utf8');
    } catch {
      // no log written
    }
    return {
      status: out.status,
      log,
      calls: readFileSync(notifyLog, 'utf8').split('\x1e').filter(Boolean),
      logMode: log === null ? null : statSync(logFile).mode & 0o777,
      dirMode: log === null ? null : statSync(join(sched, 'var', 'log')).mode & 0o777,
    };
  };
  const both = { powershell: true, notifySend: true };
  const stamp = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ FAIL /;

  const okRun = scheduled('OK app health\nOK backup\n', 0, both);
  assert.equal(okRun.status, 0);
  assert.equal(okRun.log, null, 'success logs nothing');
  assert.deepEqual(okRun.calls, [], 'success notifies nothing');

  const warnRun = scheduled('OK app health\nWARN cron: no cadran block in /secret/path\n', 0, both);
  assert.equal(warnRun.status, 0);
  assert.equal(warnRun.log, null);
  assert.deepEqual(warnRun.calls, []);

  const one = scheduled(
    'OK app health\nFAIL backup: backup older than 48h in /srv/backups/cadran-x.age\n',
    1,
    both,
  );
  assert.equal(one.status, 1);
  assert.match(one.log, new RegExp(`${stamp.source}backup\\n$`));
  assert.equal(one.logMode, 0o600);
  assert.equal(one.dirMode, 0o700);
  assert.equal(one.calls.length, 1);
  assert.equal(
    one.calls[0].startsWith(`${fakePowershell}|-NoProfile -NonInteractive -Command & { $x = `),
    true,
  );
  assert.ok(one.calls[0].endsWith(`.Show($n) } 'backup'`), one.calls[0]);
  assert.ok(!one.calls[0].includes('/srv'), 'no reason text');
  assert.ok(!one.log.includes('/srv'), 'no reason text in the log');

  const two = scheduled(
    'FAIL app health: down\nOK migrations\nFAIL database size: could not query 1234 EUR\n',
    1,
    both,
  );
  assert.match(two.log, new RegExp(`${stamp.source}app health,database size\\n$`));
  assert.ok(two.calls[0].endsWith(`.Show($n) } 'app health,database size'`));

  const unix = scheduled('FAIL migrations: pending\n', 1, { powershell: false, notifySend: true });
  assert.equal(unix.status, 1);
  assert.equal(unix.calls.length, 1);
  assert.ok(unix.calls[0].endsWith('/notify-send|Cadran: make doctor failed migrations'));
  assert.match(unix.log, new RegExp(`${stamp.source}migrations\\n$`));

  const none = scheduled('FAIL migrations: pending\nFAIL backup: x\n', 1, {
    powershell: false,
    notifySend: false,
  });
  assert.equal(none.status, 1);
  assert.deepEqual(none.calls, []);
  assert.match(none.log, new RegExp(`${stamp.source}migrations,backup\\n$`));

  console.log('Operations script tests passed.');
} finally {
  rmSync(base, { recursive: true, force: true });
}
