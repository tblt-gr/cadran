export const CACHE_PREFIX = 'cadran-shell-';

export type Route = 'bypass' | 'navigation' | 'cache-first';

interface RequestLike {
  url: string;
  method: string;
  mode: string;
}

interface RoutingContext {
  origin: string;
  /** Pathnames written into the cache at install. */
  precached: ReadonlySet<string>;
}

function isApiPath(pathname: string): boolean {
  return pathname === '/api' || pathname.startsWith('/api/');
}

/**
 * Decides how the worker answers a request. `bypass` means the worker returns before
 * `respondWith`: the browser handles the request as if no worker existed. Nothing
 * financial can be cached because only same-origin GET shell files are ever routed.
 */
export function routeRequest(request: RequestLike, context: RoutingContext): Route {
  if (request.method !== 'GET') return 'bypass';

  let url: URL;
  try {
    url = new URL(request.url);
  } catch {
    return 'bypass';
  }
  if (url.origin !== context.origin || isApiPath(url.pathname)) return 'bypass';

  if (request.mode === 'navigate') return 'navigation';
  if (url.pathname.startsWith('/assets/') || context.precached.has(url.pathname)) {
    return 'cache-first';
  }
  return 'bypass';
}

/** Guards what may be written to the cache: same-origin, outside `/api/`. */
export function isCacheableUrl(path: string): boolean {
  if (!path.startsWith('/') || path.startsWith('//')) return false;
  const pathname = new URL(path, 'https://cadran.invalid').pathname;
  return !isApiPath(pathname);
}

export function cacheNameFor(buildId: string): string {
  return `${CACHE_PREFIX}${buildId}`;
}

/** Shell caches of earlier builds, deleted on activate. Foreign caches are left alone. */
export function staleCacheNames(names: readonly string[], buildId: string): string[] {
  const current = cacheNameFor(buildId);
  return names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== current);
}
