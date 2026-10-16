import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = mkdtempSync(join(tmpdir(), 'cadran-command-check-'));
const checker = fileURLToPath(new URL('./check-documented-commands.sh', import.meta.url));
try {
  writeFileSync(
    join(directory, 'Makefile'),
    '.PHONY: imaginary\ninit: tools\nup down: end\ntools:\nend:\n',
  );
  const check = (readme, contributing = '') => {
    writeFileSync(join(directory, 'README.md'), '```sh\n' + readme + '\n```');
    writeFileSync(join(directory, 'CONTRIBUTING.md'), '```sh\n' + contributing + '\n```');
    return spawnSync('sh', [checker, directory], { encoding: 'utf8' });
  };
  assert.equal(check('`make init up` and `make down`').status, 0);
  assert.equal(check('make -j2 init LABEL="daily copy" # explanation').status, 0);
  assert.equal(check('make LABEL=daily init \\\n  up').status, 0);
  assert.equal(check('make init && make down').status, 0);
  assert.equal(check('make init unknown').status, 1);
  assert.equal(check('make init \\\n  unknown').status, 1);
  assert.equal(check('make init # make unknown').status, 0);
  assert.equal(check('make imaginary').status, 1, '.PHONY alone is not a recipe');
  assert.equal(check('make init', '`make typo`').status, 1);
  assert.match(check('make missing').stderr, /README.md:2: unknown make target "missing"/);
  assert.equal(check('`make init FILE=/tmp/contains-missing`').status, 0);
  console.log('Documented command checker tests passed.');
} finally {
  rmSync(directory, { recursive: true, force: true });
}
