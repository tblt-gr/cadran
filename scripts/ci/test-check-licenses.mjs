// Unit tests for the license gate (SEC-007): SPDX OR/AND semantics, missing license, input shapes.
import assert from 'node:assert/strict';
import { check, collectComposer, collectPnpm, isAllowed } from './check-licenses.mjs';

assert.equal(isAllowed('MIT'), true);
assert.equal(isAllowed('GPL-3.0-only'), false);
assert.equal(isAllowed('GPL-3.0-only OR MIT'), true);
assert.equal(isAllowed('GPL-3.0-only OR LGPL-2.1'), false);
assert.equal(isAllowed('MIT AND BSD-3-Clause'), true);
assert.equal(isAllowed('MIT AND GPL-3.0-only'), false);
assert.equal(isAllowed('(MIT OR GPL-3.0-only) AND ISC'), true);
assert.equal(isAllowed('MIT OR GPL-3.0-only AND LGPL-2.1'), true);
assert.equal(isAllowed('(MIT'), false);
assert.equal(isAllowed(''), false);
assert.equal(isAllowed(undefined), false);

const pnpm = collectPnpm({
  MIT: [{ name: 'a', versions: ['1.0.0', '2.0.0'], license: 'MIT' }],
  Unknown: [{ name: 'b', versions: ['3.0.0'] }],
});
assert.equal(pnpm.length, 3);
assert.deepEqual(
  check(pnpm).map(({ name, version, license }) => [name, version, license]),
  [['b', '3.0.0', 'Unknown']],
);

const composer = collectComposer({
  name: 'root/app',
  dependencies: {
    'x/ok': { version: 'v1.0.0', license: ['MIT'] },
    'x/dual': { version: 'v2.0.0', license: ['GPL-3.0-only', 'BSD-3-Clause'] },
    'x/none': { version: 'v3.0.0', license: [] },
  },
});
assert.deepEqual(
  check(composer).map(({ name }) => name),
  ['x/none'],
);

console.log('check-licenses tests passed');
