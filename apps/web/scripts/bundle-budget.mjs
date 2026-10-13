/**
 * Pure bundle accounting, kept apart from the CLI so the budget logic is testable
 * on a manifest fixture. Sizes are gzip bytes; "kB" is 1000 bytes.
 */
export const DEFAULT_BUDGETS = {
  entryJs: 180_000,
  routeJs: 120_000,
  initialCss: 40_000,
};

/** Every chunk key reachable from `keys` through static `imports`, `keys` included. */
function staticClosure(manifest, keys) {
  const seen = new Set();
  const pending = [...keys];
  while (pending.length > 0) {
    const key = pending.pop();
    if (seen.has(key)) continue;
    seen.add(key);
    pending.push(...(manifest[key]?.imports ?? []));
  }
  return seen;
}

function sum(values) {
  return values.reduce((total, value) => total + value, 0);
}

function kilobytes(bytes) {
  return (bytes / 1000).toFixed(1);
}

/**
 * @param manifest Vite's `.vite/manifest.json`.
 * @param gzipSizeOf maps a manifest `file` to its gzip size in bytes.
 * @returns the chunks to print, the entry and CSS totals, and one message per exceeded budget.
 */
export function analyzeBundle(manifest, rawGzipSizeOf, budgets = DEFAULT_BUDGETS) {
  // NaN compares false against every budget: a file missing from the build must stop the check.
  const gzipSizeOf = (file) => {
    const size = rawGzipSizeOf(file);
    if (!Number.isFinite(size)) {
      throw new Error(
        `The gzip size of ${file} is not a finite number: is the file missing from dist?`,
      );
    }
    return size;
  };

  const entryKeys = Object.keys(manifest).filter((key) => manifest[key].isEntry);
  if (entryKeys.length === 0) {
    throw new Error('The Vite manifest lists no entry chunk: was it built with build.manifest?');
  }

  const initialKeys = staticClosure(manifest, entryKeys);
  const jsOf = (keys) => sum([...keys].map((key) => gzipSizeOf(manifest[key].file)));
  const cssOf = (keys) =>
    sum([...keys].flatMap((key) => manifest[key].css ?? []).map((file) => gzipSizeOf(file)));

  const entryJs = jsOf(initialKeys);
  const initialCss = cssOf(initialKeys);
  const chunks = [{ name: entryKeys.join(', '), kind: 'entry', gzip: entryJs }];
  const violations = [];

  if (entryJs > budgets.entryJs) {
    violations.push(
      `Entry JavaScript (${chunks[0].name}) is ${kilobytes(entryJs)} kB gzip, above the ${kilobytes(budgets.entryJs)} kB budget.`,
    );
  }
  if (initialCss > budgets.initialCss) {
    violations.push(
      `Initial CSS is ${kilobytes(initialCss)} kB gzip, above the ${kilobytes(budgets.initialCss)} kB budget.`,
    );
  }

  for (const key of Object.keys(manifest).filter(
    (candidate) => manifest[candidate].isDynamicEntry,
  )) {
    const parents = Object.keys(manifest).filter((candidate) =>
      (manifest[candidate].dynamicImports ?? []).includes(key),
    );
    // A chunk requested by the entry is a route. One requested by another lazy chunk (a chart
    // loaded after its page) already finds that parent's static imports in memory.
    const isRoute = parents.some((parent) => entryKeys.includes(parent));
    const alreadyLoaded = new Set(initialKeys);
    if (!isRoute && parents.length > 0) {
      const [first, ...others] = parents.map((parent) => staticClosure(manifest, [parent]));
      for (const member of first) {
        if (others.every((closure) => closure.has(member))) alreadyLoaded.add(member);
      }
    }
    const own = [...staticClosure(manifest, [key])].filter((member) => !alreadyLoaded.has(member));
    const gzip = jsOf(own);
    const kind = isRoute ? 'route' : 'lazy';
    chunks.push({ name: key, kind, gzip });
    if (gzip > budgets.routeJs) {
      violations.push(
        `${isRoute ? 'Route' : 'Lazy'} chunk ${key} is ${kilobytes(gzip)} kB gzip, above the ${kilobytes(budgets.routeJs)} kB budget.`,
      );
    }
  }

  return { entryJs, initialCss, chunks, violations };
}

/** What a user lands on: the entry plus one route chunk (e.g. the dashboard). Informational, not a budget. */
export function landingFigure(report, routeName) {
  const route = report.chunks.find((chunk) => chunk.kind === 'route' && chunk.name === routeName);
  return route ? { name: route.name, gzip: report.entryJs + route.gzip } : null;
}
