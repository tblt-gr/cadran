import { StrictMode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';
import './styles/fonts.css';
import './styles/variables.css';
import './styles/global.css';
import './styles/primitives.css';
import './i18n';
import App from './App.tsx';
import { preloadRoute } from './AuthenticatedApp.routes';
import { clearStaleChunkFlag, recoverFromStaleChunk } from './lib/staleChunkRecovery';

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

const queryClient = new QueryClient();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
);
