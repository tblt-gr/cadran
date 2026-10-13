import { describe, expect, it } from 'vitest';
import { analyzeBundle, DEFAULT_BUDGETS, landingFigure } from './bundle-budget.mjs';

const KB = 1000;

/** A manifest shaped like Vite's: one entry, a shared chunk, two route chunks and a stylesheet. */
const manifest = {
  'index.html': {
    file: 'assets/index-a.js',
    isEntry: true,
    imports: ['_vendor.js'],
    dynamicImports: ['src/routes/Goals.tsx', 'src/routes/Reports.tsx'],
    css: ['assets/index-a.css'],
  },
  '_vendor.js': { file: 'assets/vendor-b.js' },
  '_charts.js': { file: 'assets/charts-c.js' },
  'src/routes/Goals.tsx': {
    file: 'assets/Goals-d.js',
    isDynamicEntry: true,
    imports: ['_vendor.js'],
  },
  'src/routes/Reports.tsx': {
    file: 'assets/Reports-e.js',
    isDynamicEntry: true,
    imports: ['_vendor.js', '_charts.js'],
  },
};

function sizes(overrides = {}) {
  const table = {
    'assets/index-a.js': 50 * KB,
    'assets/vendor-b.js': 100 * KB,
    'assets/charts-c.js': 90 * KB,
    'assets/Goals-d.js': 20 * KB,
    'assets/Reports-e.js': 25 * KB,
    'assets/index-a.css': 30 * KB,
    ...overrides,
  };
  return (file) => table[file];
}

describe('analyzeBundle', () => {
  it('sums the entry with its static imports and lists every chunk', () => {
    const report = analyzeBundle(manifest, sizes());

    expect(report.entryJs).toBe(150 * KB);
    expect(report.initialCss).toBe(30 * KB);
    expect(report.violations).toEqual([]);
    expect(report.chunks.map((chunk) => [chunk.name, chunk.kind, chunk.gzip])).toEqual([
      ['index.html', 'entry', 150 * KB],
      ['src/routes/Goals.tsx', 'route', 20 * KB],
      ['src/routes/Reports.tsx', 'route', 115 * KB],
    ]);
  });

  it('does not count a static import already loaded by the entry against a route', () => {
    const report = analyzeBundle(manifest, sizes());

    const goals = report.chunks.find((chunk) => chunk.name === 'src/routes/Goals.tsx');
    expect(goals?.gzip).toBe(20 * KB);
  });

  it('flags an entry above its budget', () => {
    const report = analyzeBundle(manifest, sizes({ 'assets/vendor-b.js': 131 * KB + 1 }));

    expect(report.violations).toHaveLength(1);
    expect(report.violations[0]).toContain('index.html');
    expect(report.violations[0]).toContain('180');
  });

  it('flags a route chunk above its budget, counting the shared chunks it alone imports', () => {
    const report = analyzeBundle(manifest, sizes({ 'assets/charts-c.js': 96 * KB }));

    expect(report.violations).toHaveLength(1);
    expect(report.violations[0]).toContain('src/routes/Reports.tsx');
    expect(report.violations[0]).toContain('120');
  });

  it('flags an initial stylesheet above its budget', () => {
    const report = analyzeBundle(manifest, sizes({ 'assets/index-a.css': 40 * KB + 1 }));

    expect(report.violations).toHaveLength(1);
    expect(report.violations[0]).toContain('CSS');
  });

  it('accepts a size exactly at the budget', () => {
    const report = analyzeBundle(manifest, sizes({ 'assets/index-a.css': 40 * KB }), {
      ...DEFAULT_BUDGETS,
    });

    expect(report.violations).toEqual([]);
  });

  it('fails when the manifest has no entry rather than passing on nothing', () => {
    expect(() => analyzeBundle({}, sizes())).toThrow(/entry/i);
  });

  describe('chunks requested by another lazy chunk', () => {
    /** Reports lazily asks for a chart chunk that shares a helper chunk with it. */
    const nested = {
      ...manifest,
      'src/routes/Reports.tsx': {
        ...manifest['src/routes/Reports.tsx'],
        imports: ['_vendor.js', '_helpers.js'],
        dynamicImports: ['src/charts/Flows.tsx'],
      },
      '_helpers.js': { file: 'assets/helpers-f.js' },
      'src/charts/Flows.tsx': {
        file: 'assets/Flows-g.js',
        isDynamicEntry: true,
        imports: ['_vendor.js', '_helpers.js', '_charts.js'],
      },
    };
    const nestedSizes = (overrides = {}) => sizes({ 'assets/helpers-f.js': 10 * KB, ...overrides });

    it('does not count what the parent chunk already loaded against the nested chunk', () => {
      const report = analyzeBundle(nested, (file) =>
        file === 'assets/Flows-g.js' ? 15 * KB : nestedSizes()(file),
      );

      const flows = report.chunks.find((chunk) => chunk.name === 'src/charts/Flows.tsx');
      // charts (90) + own code (15); the 10 kB helper chunk came with Reports.
      expect(flows).toMatchObject({ kind: 'lazy', gzip: 105 * KB });
      expect(report.violations).toEqual([]);
    });

    it('still flags a nested chunk whose own load is above the budget', () => {
      const report = analyzeBundle(nested, (file) =>
        file === 'assets/Flows-g.js' ? 31 * KB : nestedSizes()(file),
      );

      expect(report.violations).toHaveLength(1);
      expect(report.violations[0]).toContain('src/charts/Flows.tsx');
    });
  });

  it('throws on a size that is not a finite number instead of letting NaN pass every budget', () => {
    const missing = (file) => (file === 'assets/Goals-d.js' ? undefined : sizes()(file));

    expect(() => analyzeBundle(manifest, missing)).toThrow(/assets\/Goals-d\.js/);
    expect(() => analyzeBundle(manifest, () => Number.NaN)).toThrow(/finite/);
  });

  it('adds the entry and a named route for the landing figure', () => {
    const report = analyzeBundle(manifest, sizes());

    expect(landingFigure(report, 'src/routes/Goals.tsx')).toEqual({
      name: 'src/routes/Goals.tsx',
      gzip: 170 * KB,
    });
  });

  it('has no landing figure when the route is not in the manifest', () => {
    expect(landingFigure(analyzeBundle(manifest, sizes()), 'src/routes/Nope.tsx')).toBeNull();
  });
});
