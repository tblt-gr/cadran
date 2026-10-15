import { describe, expect, it } from 'vitest';
import { buildIdFor, injectPrecache, precachePaths } from './sw-precache.mjs';

const manifest = {
  'index.html': {
    file: 'assets/index-a.js',
    isEntry: true,
    css: ['assets/index-a.css'],
    assets: ['assets/inter-b.woff2'],
  },
  'src/routes/Goals.tsx': { file: 'assets/Goals-c.js', isDynamicEntry: true },
};

describe('precachePaths', () => {
  it('lists the shell, the manifest files, the assets on disk and the static files', () => {
    const paths = precachePaths({
      manifest,
      assetFiles: ['assets/inter-b.woff2', 'assets/index-a.js.map', 'assets/Goals-c.js'],
      staticFiles: ['icons/icon-192.png', 'offline.html', 'offline.css'],
    });

    expect(paths).toEqual([
      '/assets/Goals-c.js',
      '/assets/index-a.css',
      '/assets/index-a.js',
      '/assets/inter-b.woff2',
      '/icons/icon-192.png',
      '/index.html',
      '/offline.css',
      '/offline.html',
    ]);
  });

  it('never lists a source map or an API path', () => {
    const paths = precachePaths({
      manifest,
      assetFiles: ['assets/a.js.map'],
      staticFiles: ['api/leak.json'],
    });
    expect(paths.some((path) => path.endsWith('.map'))).toBe(false);
    expect(paths.some((path) => path.startsWith('/api/'))).toBe(false);
  });

  it('rejects a path the worker could not carry in its comma separated list', () => {
    expect(() => precachePaths({ manifest, assetFiles: [], staticFiles: ['a,b.png'] })).toThrow(
      /comma/,
    );
  });
});

describe('buildIdFor', () => {
  it('changes with the content and not with the order', () => {
    const a = buildIdFor([
      ['/x', 'one'],
      ['/y', 'two'],
    ]);
    expect(
      buildIdFor([
        ['/y', 'two'],
        ['/x', 'one'],
      ]),
    ).toBe(a);
    expect(
      buildIdFor([
        ['/x', 'one'],
        ['/y', 'three'],
      ]),
    ).not.toBe(a);
    expect(a).toMatch(/^[0-9a-f]{16}$/);
  });
});

describe('injectPrecache', () => {
  const source = `const a="__CADRAN_BUILD_ID__";const b='__CADRAN_PRECACHE__';`;

  it('replaces both tokens', () => {
    const out = injectPrecache(source, 'abc', ['/index.html', '/offline.html']);
    expect(out).toBe(`const a="abc";const b='/index.html,/offline.html';`);
  });

  it('fails when a token is missing or repeated', () => {
    expect(() => injectPrecache('const a=1;', 'abc', [])).toThrow(/__CADRAN_BUILD_ID__/);
    expect(() => injectPrecache(`${source}${source}`, 'abc', [])).toThrow(/exactly once/);
  });
});
