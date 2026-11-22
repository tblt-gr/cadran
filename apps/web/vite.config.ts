import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// The root package carries the product version; workspace packages stay at 0.0.0.
const { version } = JSON.parse(
  readFileSync(fileURLToPath(new URL('../../package.json', import.meta.url)), 'utf8'),
) as { version: string };

export default defineConfig({
  plugins: [react()],
  define: { __APP_VERSION__: JSON.stringify(version) },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // Shared fixtures live at the repo root so PHPUnit and Vitest read the
      // same cases. A relative import from src/ would trip
      // import/no-relative-parent-imports.
      '@contracts': fileURLToPath(new URL('../../tests/contracts', import.meta.url)),
      '@fixtures': fileURLToPath(new URL('../../tests/fixtures', import.meta.url)),
    },
  },
  build: {
    // Keep in sync with "Supported browsers" in README.md.
    target: ['chrome107', 'edge107', 'firefox116', 'safari16'],
    // Read by scripts/check-bundle-budget.mjs to enforce the bundle budgets.
    manifest: true,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test-setup.ts'],
    // Only active with --coverage (CI); plain `vitest run` stays driver-free.
    coverage: {
      provider: 'v8',
      reporter: ['text-summary', 'cobertura', 'html'],
      reportsDirectory: 'coverage',
      include: ['src/**'],
      exclude: [
        'src/**/*.spec.{ts,tsx}',
        'src/**/*.d.ts',
        'src/**/*[Ff]ixture*.{ts,tsx}',
        'src/test-setup.ts',
      ],
    },
  },
});
