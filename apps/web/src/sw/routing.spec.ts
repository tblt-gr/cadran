import { describe, expect, it } from 'vitest';
import {
  CACHE_PREFIX,
  cacheNameFor,
  isCacheableUrl,
  routeRequest,
  staleCacheNames,
} from './routing';

const origin = 'https://cadran.test';
const precached = new Set(['/', '/index.html', '/offline.html', '/icons/icon-192.png']);

function route(url: string, init: { method?: string; mode?: string; destination?: string } = {}) {
  return routeRequest(
    { url, method: init.method ?? 'GET', mode: init.mode ?? 'no-cors' },
    { origin, precached },
  );
}

describe('routeRequest', () => {
  it('never intercepts API requests, whatever the method or mode', () => {
    expect(route(`${origin}/api/v1/accounts`)).toBe('bypass');
    expect(route(`${origin}/api/v1/accounts`, { mode: 'navigate' })).toBe('bypass');
    expect(route(`${origin}/api/v1/transactions`, { method: 'POST' })).toBe('bypass');
    expect(route(`${origin}/api`)).toBe('bypass');
  });

  it('never intercepts a non-GET request', () => {
    expect(route(`${origin}/assets/index-abc.js`, { method: 'POST' })).toBe('bypass');
    expect(route(`${origin}/`, { method: 'PUT', mode: 'navigate' })).toBe('bypass');
  });

  it('never intercepts a cross-origin request', () => {
    expect(route('https://evil.test/assets/index-abc.js')).toBe('bypass');
    expect(route('https://evil.test/', { mode: 'navigate' })).toBe('bypass');
  });

  it('serves navigations network first with the shell as fallback', () => {
    expect(route(`${origin}/transactions`, { mode: 'navigate' })).toBe('navigation');
    expect(route(`${origin}/`, { mode: 'navigate' })).toBe('navigation');
  });

  it('serves hashed assets and precached files cache first', () => {
    expect(route(`${origin}/assets/index-abc123.js`)).toBe('cache-first');
    expect(route(`${origin}/assets/inter-latin.woff2`)).toBe('cache-first');
    expect(route(`${origin}/icons/icon-192.png`)).toBe('cache-first');
  });

  it('lets any other same-origin request through uncached', () => {
    expect(route(`${origin}/something-else.json`)).toBe('bypass');
    expect(route(`${origin}/sw.js`)).toBe('bypass');
  });

  it('ignores the query string and fragment when matching precached files', () => {
    expect(route(`${origin}/icons/icon-192.png?v=1`)).toBe('cache-first');
  });

  it('treats an unparsable URL as a bypass', () => {
    expect(route('not a url')).toBe('bypass');
  });
});

describe('isCacheableUrl', () => {
  it('refuses API, cross-origin and non-http URLs', () => {
    expect(isCacheableUrl('/api/v1/accounts')).toBe(false);
    expect(isCacheableUrl('/api')).toBe(false);
    expect(isCacheableUrl('https://evil.test/assets/a.js')).toBe(false);
    expect(isCacheableUrl('//evil.test/assets/a.js')).toBe(false);
    expect(isCacheableUrl('/assets/../api/v1/accounts')).toBe(false);
  });

  it('accepts same-origin shell files', () => {
    expect(isCacheableUrl('/assets/index-abc.js')).toBe(true);
    expect(isCacheableUrl('/offline.html')).toBe(true);
    expect(isCacheableUrl('/')).toBe(true);
  });
});

describe('cache names', () => {
  it('names the cache after the build and finds the older ones', () => {
    expect(cacheNameFor('abc')).toBe(`${CACHE_PREFIX}abc`);
    expect(
      staleCacheNames(
        [`${CACHE_PREFIX}old`, `${CACHE_PREFIX}abc`, 'other-app-cache', `${CACHE_PREFIX}older`],
        'abc',
      ),
    ).toEqual([`${CACHE_PREFIX}old`, `${CACHE_PREFIX}older`]);
  });
});
