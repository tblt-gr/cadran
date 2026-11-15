// License gate for production dependencies (SEC-007).
// Usage: node scripts/ci/check-licenses.mjs <pnpm-licenses.json> <composer-licenses.json> <report.json>
// Inputs are `pnpm licenses list --prod --json` and `composer licenses --no-dev --format=json`.
// Writes the merged report and exits 1 when a package carries a license outside the allowlist.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

export const ALLOWED = new Set([
  'MIT',
  'ISC',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'Apache-2.0',
  '0BSD',
  'OFL-1.1',
  'CC0-1.0',
  'BlueOak-1.0.0',
  'PostgreSQL',
]);

const tokenize = (expression) => expression.match(/\(|\)|[^\s()]+/g) ?? [];

// SPDX grammar: OR binds looser than AND. An unknown or empty expression is not allowed.
export const isAllowed = (expression, allowed = ALLOWED) => {
  const tokens = tokenize(String(expression ?? ''));
  let position = 0;
  const parseOr = () => {
    let result = parseAnd();
    while (tokens[position]?.toUpperCase() === 'OR') {
      position += 1;
      const right = parseAnd();
      result = result || right;
    }
    return result;
  };
  const parseAnd = () => {
    let result = parseAtom();
    while (tokens[position]?.toUpperCase() === 'AND') {
      position += 1;
      const right = parseAtom();
      result = result && right;
    }
    return result;
  };
  const parseAtom = () => {
    const token = tokens[position];
    if (token === undefined) {
      throw new Error('empty');
    }
    position += 1;
    if (token === '(') {
      const result = parseOr();
      if (tokens[position] !== ')') {
        throw new Error('unbalanced');
      }
      position += 1;
      return result;
    }
    return allowed.has(token);
  };
  try {
    const result = parseOr();
    return position === tokens.length && result;
  } catch {
    return false;
  }
};

export const collectPnpm = (report) =>
  Object.entries(report).flatMap(([license, packages]) =>
    packages.flatMap((entry) =>
      (entry.versions?.length ? entry.versions : [null]).map((version) => ({
        ecosystem: 'pnpm',
        name: entry.name,
        version,
        license: entry.license ?? license,
      })),
    ),
  );

export const collectComposer = (report) =>
  Object.entries(report.dependencies ?? {}).map(([name, entry]) => ({
    ecosystem: 'composer',
    name,
    version: entry.version ?? null,
    license: Array.isArray(entry.license) ? entry.license.join(' OR ') : (entry.license ?? ''),
  }));

export const check = (packages) => packages.filter(({ license }) => !isAllowed(license));

const main = () => {
  const [pnpmPath, composerPath, outputPath] = process.argv.slice(2);
  if (!pnpmPath || !composerPath || !outputPath) {
    console.error('Usage: check-licenses.mjs <pnpm.json> <composer.json> <report.json>');
    process.exit(2);
  }
  const packages = [
    ...collectPnpm(JSON.parse(readFileSync(pnpmPath, 'utf8'))),
    ...collectComposer(JSON.parse(readFileSync(composerPath, 'utf8'))),
  ].sort((a, b) =>
    `${a.ecosystem}/${a.name}/${a.version}`.localeCompare(`${b.ecosystem}/${b.name}/${b.version}`),
  );
  const violations = check(packages);
  writeFileSync(
    outputPath,
    `${JSON.stringify({ allowlist: [...ALLOWED], packages, violations }, null, 2)}\n`,
  );
  for (const { ecosystem, name, version, license } of violations) {
    console.error(
      `License not allowed: ${ecosystem} ${name}@${version ?? '?'}: ${license || 'missing'}`,
    );
  }
  console.log(`${packages.length} production packages checked, ${violations.length} violation(s)`);
  process.exit(violations.length === 0 ? 0 : 1);
};

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main();
}
