import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
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
  test: {
    environment: 'jsdom',
  },
});
