import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// The worker is its own build so it keeps the unhashed name `/sw.js` the browser
// needs to detect updates, and stays out of the app manifest and bundle budget.
export default defineConfig({
  publicDir: false,
  build: {
    emptyOutDir: false,
    copyPublicDir: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/sw/service-worker.ts', import.meta.url)),
      output: { entryFileNames: 'sw.js', format: 'iife', inlineDynamicImports: true },
    },
  },
});
