// Infrastructure tests for scripts/install-cron.sh and `backup.sh health --strict` (OPS-006).
// No Docker, no real crontab: a fake `crontab` on PATH stores the table in a file.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
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

  console.log('Operations script tests passed.');
} finally {
  rmSync(base, { recursive: true, force: true });
}
