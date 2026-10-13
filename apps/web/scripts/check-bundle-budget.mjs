import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { analyzeBundle, DEFAULT_BUDGETS, landingFigure } from './bundle-budget.mjs';

const distRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'dist');
const manifest = JSON.parse(await readFile(path.join(distRoot, '.vite', 'manifest.json'), 'utf8'));

const gzipSizes = new Map();
for (const entry of Object.values(manifest)) {
  for (const file of [entry.file, ...(entry.css ?? [])]) {
    // Fonts and images listed in the manifest are not part of the JavaScript and CSS budgets.
    if (/\.(?:js|css)$/.test(file) && !gzipSizes.has(file)) {
      gzipSizes.set(file, gzipSync(await readFile(path.join(distRoot, file))).length);
    }
  }
}

const kilobytes = (bytes) => `${(bytes / 1000).toFixed(1)} kB`;
const report = analyzeBundle(manifest, (file) => gzipSizes.get(file), DEFAULT_BUDGETS);

console.log('Emitted files (gzip):');
for (const [file, size] of [...gzipSizes].sort((a, b) => b[1] - a[1])) {
  console.log(`  ${kilobytes(size).padStart(10)}  ${file}`);
}
console.log('Budgeted loads (gzip, with static imports not already in the entry):');
for (const chunk of report.chunks) {
  console.log(`  ${kilobytes(chunk.gzip).padStart(10)}  ${chunk.kind.padEnd(5)} ${chunk.name}`);
}
const landing = landingFigure(report, 'src/features/dashboard/DashboardPage.tsx');
if (landing) {
  console.log(
    `  ${kilobytes(landing.gzip).padStart(10)}  load  entry + ${landing.name} (landing, informational)`,
  );
}
console.log(`  ${kilobytes(report.initialCss).padStart(10)}  css   initial stylesheet`);

if (report.violations.length > 0) {
  console.error(['Bundle budget exceeded:', ...report.violations.map((v) => `- ${v}`)].join('\n'));
  process.exitCode = 1;
} else {
  console.log('Bundle budgets respected.');
}
