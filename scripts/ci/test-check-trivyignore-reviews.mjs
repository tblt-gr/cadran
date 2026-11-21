// Deterministic tests for the .trivyignore review-date gate: the date is injected, never read from
// the machine clock.
import assert from 'node:assert/strict';
import { checkTrivyIgnore } from './check-trivyignore-reviews.mjs';

const at = (iso) => new Date(iso);
const file = (review, cves = ['CVE-2026-0001']) =>
  `# header\n\n# review: ${review}\n# reason\n${cves.join('\n')}\n`;
const run = (content, today, strict = false) => checkTrivyIgnore(content, { today, strict });

// Review day at any time of day, then the day after.
assert.deepEqual(run(file('2026-12-01'), at('2026-12-01T00:00:00Z')), { errors: [], warnings: [] });
assert.deepEqual(run(file('2026-12-01'), at('2026-12-01T23:59:59Z'), true), {
  errors: [],
  warnings: [],
});
assert.equal(run(file('2026-12-01'), at('2026-12-02T00:00:00Z')).warnings.length, 1);
assert.equal(run(file('2026-12-01'), at('2026-12-02T00:00:00Z')).errors.length, 0);
assert.equal(run(file('2026-12-01'), at('2026-12-02T00:00:00Z'), true).errors.length, 1);
assert.equal(run(file('2026-12-01'), at('2026-11-30T23:59:59Z'), true).errors.length, 0);

// Inclusive 90-day boundary: 2026-12-01 + 90 days is 2027-03-01.
assert.equal(run(file('2027-03-01'), at('2026-12-01T12:00:00Z'), true).errors.length, 0);
assert.equal(run(file('2027-03-02'), at('2026-12-01T12:00:00Z')).warnings.length, 1);
assert.equal(run(file('2027-03-02'), at('2026-12-01T12:00:00Z'), true).errors.length, 1);

// Real leap day versus impossible dates.
assert.equal(run(file('2028-02-29'), at('2028-02-01T00:00:00Z'), true).errors.length, 0);
for (const bad of [
  '2027-02-29',
  '2026-13-01',
  '2026-00-10',
  '2026-04-31',
  '2026-1-1',
  '26-12-01',
  'soon',
  '',
]) {
  assert.equal(run(file(bad), at('2026-12-01T00:00:00Z')).errors.length, 1, bad);
}

// Missing and duplicate metadata fail even in ordinary mode.
const today = at('2026-12-01T00:00:00Z');
assert.equal(run('# reason\nCVE-2026-0001\n', today).errors.length, 1);
assert.equal(run('CVE-2026-0001\n', today).errors.length, 1);
assert.equal(
  run('# review: 2026-12-02\n# review: 2026-12-03\nCVE-2026-0001\n', today).errors.length,
  1,
);

// Several CVEs share their block date; adjacent blocks keep their own.
assert.deepEqual(
  run(file('2026-12-31', ['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']), today),
  { errors: [], warnings: [] },
);
const adjacentMissing = '# review: 2026-12-31\nCVE-2026-0001\n# reason only\nCVE-2026-0002\n';
assert.equal(run(adjacentMissing, today).errors.length, 1);
const adjacent = '# review: 2026-12-31\nCVE-2026-0001\n# review: 2025-01-01\nCVE-2026-0002\n';
const adjacentResult = run(adjacent, today);
assert.equal(adjacentResult.errors.length, 0);
assert.equal(adjacentResult.warnings.length, 1);
assert.match(adjacentResult.warnings[0], /CVE-2026-0002.*2025-01-01/);

// A file without entries passes, in both modes.
assert.deepEqual(run('', today, true), { errors: [], warnings: [] });
assert.deepEqual(run('# only comments\n# review: nonsense\n', today, true), {
  errors: [],
  warnings: [],
});

console.log('trivyignore review checks passed');
