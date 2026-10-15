import { describe, expect, it, vi } from 'vitest';
import { CACHE_PREFIX } from './routing';
import { createWorker, type CacheStorageLike } from './worker';

const origin = 'https://cadran.test';

class FakeCache {
  readonly entries = new Map<string, Response>();
  async addAll(requests: readonly Request[]) {
    for (const request of requests)
      this.entries.set(new URL(request.url).pathname, new Response('x'));
  }
  async put(request: Request | string, response: Response) {
    const url = typeof request === 'string' ? request : request.url;
    this.entries.set(new URL(url, origin).pathname, response);
  }
  async match(request: Request | string) {
    const url = typeof request === 'string' ? request : request.url;
    return this.entries.get(new URL(url, origin).pathname);
  }
}

class FakeCacheStorage implements CacheStorageLike {
  readonly stores = new Map<string, FakeCache>();
  async open(name: string) {
    if (!this.stores.has(name)) this.stores.set(name, new FakeCache());
    return this.stores.get(name)!;
  }
  async keys() {
    return [...this.stores.keys()];
  }
  async delete(name: string) {
    return this.stores.delete(name);
  }
  async match(request: Request | string) {
    for (const store of this.stores.values()) {
      const hit = await store.match(request);
      if (hit) return hit;
    }
    return undefined;
  }
}

function setup(fetchImpl: typeof fetch = vi.fn(async () => new Response('network'))) {
  const caches = new FakeCacheStorage();
  const worker = createWorker({
    buildId: 'b2',
    origin,
    precache: ['/index.html', '/offline.html', '/assets/index-a.js', '/api/v1/leak'],
    caches,
    fetch: fetchImpl,
  });
  return { caches, worker };
}

function request(path: string, init: RequestInit = {}) {
  return new Request(`${origin}${path}`, init);
}

describe('service worker lifecycle', () => {
  it('precaches the shell files and never an API URL', async () => {
    const { caches, worker } = setup();
    await worker.install();

    const store = caches.stores.get(`${CACHE_PREFIX}b2`)!;
    expect([...store.entries.keys()].sort()).toEqual([
      '/assets/index-a.js',
      '/index.html',
      '/offline.html',
    ]);
    for (const store of caches.stores.values()) {
      for (const key of store.entries.keys()) expect(key).not.toContain('/api/');
    }
  });

  it('deletes older shell caches on activate and keeps foreign ones', async () => {
    const { caches, worker } = setup();
    await caches.open(`${CACHE_PREFIX}b1`);
    await caches.open('someone-else');
    await worker.install();
    await worker.activate();

    expect((await caches.keys()).sort()).toEqual([`${CACHE_PREFIX}b2`, 'someone-else']);
  });
});

describe('service worker fetch', () => {
  it('does not answer API requests and never touches the cache for them', async () => {
    const fetchSpy = vi.fn(async () => new Response('secret'));
    const { caches, worker } = setup(fetchSpy);
    await worker.install();

    expect(worker.handleFetch(request('/api/v1/accounts'))).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
    for (const store of caches.stores.values()) {
      expect([...store.entries.keys()].some((key) => key.startsWith('/api'))).toBe(false);
    }
  });

  it('returns the network response for a navigation and writes nothing', async () => {
    const { caches, worker } = setup();
    await worker.install();
    const before = [...caches.stores.get(`${CACHE_PREFIX}b2`)!.entries.keys()];

    const response = await worker.handleFetch(request('/budget', { headers: {} }), 'navigate');
    expect(await response!.text()).toBe('network');
    expect([...caches.stores.get(`${CACHE_PREFIX}b2`)!.entries.keys()]).toEqual(before);
  });

  it('falls back to the precached index when the network fails', async () => {
    const { worker } = setup(vi.fn(async () => Promise.reject(new TypeError('offline'))));
    await worker.install();

    const response = await worker.handleFetch(request('/budget'), 'navigate');
    expect(response).toBeInstanceOf(Response);
    expect(await response!.text()).toBe('x');
  });

  it('serves offline.html when the shell is not in the cache, and an error when nothing is', async () => {
    const failing = vi.fn(async () => Promise.reject(new TypeError('offline')));
    const { caches, worker } = setup(failing);
    const store = await caches.open(`${CACHE_PREFIX}b2`);
    await store.put('/offline.html', new Response('offline page'));

    const response = await worker.handleFetch(request('/budget'), 'navigate');
    expect(await response!.text()).toBe('offline page');

    const empty = setup(failing);
    const error = await empty.worker.handleFetch(request('/budget'), 'navigate');
    expect(error!.type).toBe('error');
  });

  it('serves a hashed asset from the cache without the network', async () => {
    const fetchSpy = vi.fn(async () => new Response('network'));
    const { worker } = setup(fetchSpy);
    await worker.install();

    const response = await worker.handleFetch(request('/assets/index-a.js'));
    expect(await response!.text()).toBe('x');
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it('fetches an uncached hashed asset from the network without storing it', async () => {
    const { caches, worker } = setup();
    await worker.install();

    const response = await worker.handleFetch(request('/assets/new-b.js'));
    expect(await response!.text()).toBe('network');
    expect(caches.stores.get(`${CACHE_PREFIX}b2`)!.entries.has('/assets/new-b.js')).toBe(false);
  });
});
