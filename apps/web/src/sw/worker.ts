import { cacheNameFor, isCacheableUrl, routeRequest, staleCacheNames } from './routing';

interface CacheLike {
  addAll(requests: readonly Request[]): Promise<void>;
  match(request: Request | string): Promise<Response | undefined>;
}

export interface CacheStorageLike {
  open(name: string): Promise<CacheLike>;
  keys(): Promise<string[]>;
  delete(name: string): Promise<boolean>;
  match(request: Request | string): Promise<Response | undefined>;
}

interface WorkerOptions {
  buildId: string;
  origin: string;
  /** Pathnames of the shell files written to the cache at install. */
  precache: readonly string[];
  caches: CacheStorageLike;
  fetch: typeof fetch;
}

/** The worker's behaviour, free of the worker global so tests can drive it directly. */
export function createWorker(options: WorkerOptions) {
  const cacheName = cacheNameFor(options.buildId);
  const files = options.precache.filter(isCacheableUrl);
  const precached = new Set(files);

  async function install(): Promise<void> {
    const cache = await options.caches.open(cacheName);
    // `reload` skips the HTTP cache so a stale intermediary copy cannot be pinned.
    await cache.addAll(
      files.map((path) => new Request(new URL(path, options.origin), { cache: 'reload' })),
    );
  }

  async function activate(): Promise<void> {
    const names = await options.caches.keys();
    await Promise.all(
      staleCacheNames(names, options.buildId).map((name) => options.caches.delete(name)),
    );
  }

  async function navigate(request: Request): Promise<Response> {
    try {
      return await options.fetch(request);
    } catch {
      const cache = await options.caches.open(cacheName);
      return (
        (await cache.match('/index.html')) ??
        (await cache.match('/offline.html')) ??
        Response.error()
      );
    }
  }

  async function cacheFirst(request: Request): Promise<Response> {
    const cache = await options.caches.open(cacheName);
    return (await cache.match(request)) ?? options.fetch(request);
  }

  /** `null` means the worker does not answer: the browser handles the request itself. */
  function handleFetch(request: Request, mode: string = request.mode): Promise<Response> | null {
    const route = routeRequest(
      { url: request.url, method: request.method, mode },
      { origin: options.origin, precached },
    );
    if (route === 'navigation') return navigate(request);
    if (route === 'cache-first') return cacheFirst(request);
    return null;
  }

  return { install, activate, handleFetch };
}
