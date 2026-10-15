import { createWorker, type CacheStorageLike } from './worker';

// The DOM library the app is typed with has no worker globals, and the worker must
// not pull the webworker library in next to it: only what is used is declared here.
interface ExtendableEventLike extends Event {
  waitUntil(promise: Promise<unknown>): void;
}
interface FetchEventLike extends ExtendableEventLike {
  request: Request;
  respondWith(response: Promise<Response>): void;
}
interface MessageEventLike extends ExtendableEventLike {
  data: unknown;
}
interface WorkerScope {
  location: Location;
  caches: CacheStorageLike;
  addEventListener(type: 'install' | 'activate', listener: (e: ExtendableEventLike) => void): void;
  addEventListener(type: 'fetch', listener: (e: FetchEventLike) => void): void;
  addEventListener(type: 'message', listener: (e: MessageEventLike) => void): void;
  skipWaiting(): Promise<void>;
}

const scope = self as unknown as WorkerScope;

// Both tokens are replaced in dist/sw.js by scripts/write-sw-precache.mjs after the build.
const BUILD_ID = '__CADRAN_BUILD_ID__';
const PRECACHE = '__CADRAN_PRECACHE__';

const worker = createWorker({
  buildId: BUILD_ID,
  origin: scope.location.origin,
  precache: PRECACHE.split(',').filter((path) => path !== ''),
  caches: scope.caches,
  fetch: (input, init) => fetch(input, init),
});

scope.addEventListener('install', (event) => {
  // No skipWaiting here: a new worker waits until the user accepts the update.
  event.waitUntil(worker.install());
});

scope.addEventListener('activate', (event) => {
  event.waitUntil(worker.activate());
});

scope.addEventListener('fetch', (event) => {
  const response = worker.handleFetch(event.request);
  if (response !== null) event.respondWith(response);
});

scope.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') event.waitUntil(scope.skipWaiting());
});
