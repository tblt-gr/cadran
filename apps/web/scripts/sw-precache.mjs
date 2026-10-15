import { createHash } from 'node:crypto';

const BUILD_ID_TOKEN = '__CADRAN_BUILD_ID__';
const PRECACHE_TOKEN = '__CADRAN_PRECACHE__';

/**
 * The shell files the worker writes to its cache: `index.html`, everything the Vite
 * manifest or `dist/assets` holds (scripts, styles, fonts) and the static files
 * (icons, offline page). Paths are relative to `dist`; the result is sorted, absolute
 * and free of anything the worker must never cache.
 */
export function precachePaths({ manifest, assetFiles, staticFiles }) {
  const files = new Set(['index.html', ...staticFiles]);
  for (const entry of Object.values(manifest)) {
    for (const file of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) files.add(file);
  }
  for (const file of assetFiles) files.add(file);

  const paths = [...files].filter((file) => !file.endsWith('.map')).map((file) => `/${file}`);
  for (const path of paths) {
    if (path.includes(',')) {
      throw new Error(
        `The precache path ${path} contains a comma, which the worker list cannot carry.`,
      );
    }
  }
  return paths.filter((path) => !path.startsWith('/api/')).sort();
}

/** @param entries pairs of [path, content]; the id changes whenever any shell file does. */
export function buildIdFor(entries) {
  const hash = createHash('sha256');
  for (const [path, content] of [...entries].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))) {
    hash.update(path).update('\0').update(content).update('\0');
  }
  return hash.digest('hex').slice(0, 16);
}

/** Writes the build id and the file list into the built worker source. */
export function injectPrecache(source, buildId, paths) {
  for (const token of [BUILD_ID_TOKEN, PRECACHE_TOKEN]) {
    const count = source.split(token).length - 1;
    if (count === 0) throw new Error(`The worker source has no ${token} token to replace.`);
    if (count > 1) throw new Error(`The token ${token} must appear exactly once in the worker.`);
  }
  return source
    .replace(BUILD_ID_TOKEN, () => buildId)
    .replace(PRECACHE_TOKEN, () => paths.join(','));
}
