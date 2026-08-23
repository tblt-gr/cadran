import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import './i18n';
import App from './App';

function renderApp() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>,
  );
}

describe('App', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('announces loading while the API request is pending', () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderApp();

    expect(screen.getByRole('status').textContent).toContain('Connexion à l’API');
  });

  it('renders the version returned through the generated API client', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        Promise.resolve(
          new Response(JSON.stringify({ status: 'ready', apiVersion: 'v1' }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          }),
        ),
      ),
    );

    renderApp();

    expect(await screen.findByText('API v1')).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('Le socle de l’application est prêt.');
  });

  it('shows a recoverable error for a network failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );

    renderApp();

    expect((await screen.findByRole('alert')).textContent).toContain(
      'L’API est actuellement indisponible.',
    );
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });
});
