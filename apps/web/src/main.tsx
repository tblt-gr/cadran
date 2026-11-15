import { StrictMode } from 'react';
import { client } from '@cadran/api-client/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import './styles/fonts.css';
import './styles/variables.css';
import './styles/global.css';
import './styles/primitives.css';
import App from './App.tsx';
import { initI18n } from './i18n';
import { preloadRoute } from './AuthenticatedApp.routes';
import { registerSessionExpiry } from './features/auth/sessionExpiry';
import { registerServiceWorker } from './sw/register';
import { clearStaleChunkFlag, recoverFromStaleChunk } from './lib/staleChunkRecovery';

// The dictionary is its own chunk, requested in parallel with the entry and awaited
// before the first render so no label shows untranslated.
const i18nReady = initI18n();

// Request the chunk of the URL now, in parallel with the session probe, rather than
// when the authenticated tree first renders it.
preloadRoute(window.location.pathname);

// Vite fires this when a chunk is missing, typically a stale tab after an upgrade.
window.addEventListener('vite:preloadError', (event) => {
  recoverFromStaleChunk(event, () => window.location.reload());
});
// A reload that survives a few seconds without another failure was healthy.
window.addEventListener('load', () => {
  window.setTimeout(clearStaleChunkFlag, 5000);
});

registerServiceWorker({
  environment: {
    isProduction: import.meta.env.PROD,
    protocol: window.location.protocol,
    hasServiceWorker: 'serviceWorker' in navigator,
  },
  container: navigator.serviceWorker,
  onLoad: (listener) => window.addEventListener('load', listener),
});

const queryClient = new QueryClient();
registerSessionExpiry(client, queryClient);

i18nReady
  .then(() => {
    createRoot(document.getElementById('root')!).render(
      <StrictMode>
        <QueryClientProvider client={queryClient}>
          <App />
        </QueryClientProvider>
      </StrictMode>,
    );
  })
  .catch((error: unknown) => {
    // A stale tab after an upgrade asks for a dictionary chunk the server no longer has.
    recoverFromStaleChunk(new ErrorEvent('vite:preloadError', { error }), () =>
      window.location.reload(),
    );
  });
