import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildIdFor, injectPrecache, precachePaths } from './sw-precache.mjs';

const distRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'dist');

async function listFiles(directory, prefix) {
  const entries = await readdir(path.join(distRoot, directory), { withFileTypes: true });
  return entries.filter((entry) => entry.isFile()).map((entry) => `${prefix}${entry.name}`);
}

const manifest = JSON.parse(await readFile(path.join(distRoot, '.vite', 'manifest.json'), 'utf8'));
const paths = precachePaths({
  manifest,
  assetFiles: await listFiles('assets', 'assets/'),
  staticFiles: [...(await listFiles('icons', 'icons/')), 'offline.html', 'offline.css'],
});

const contents = await Promise.all(
  paths.map(async (file) => [file, await readFile(path.join(distRoot, file))]),
);
const buildId = buildIdFor(contents);

const workerPath = path.join(distRoot, 'sw.js');
const source = await readFile(workerPath, 'utf8');
await writeFile(workerPath, injectPrecache(source, buildId, paths));

console.log(`Service worker ${buildId}: ${paths.length} shell files precached.`);
