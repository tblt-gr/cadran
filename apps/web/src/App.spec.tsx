import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
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

function respondWithReadyFoundation() {
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
}

describe('App', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
  });

  it('keeps the application shell visible while the API request is pending', () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderApp();

    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('Connexion à l’API');
  });

  it.each([
    ['/', 'Synthèse'],
    ['/transactions', 'Transactions'],
    ['/months/2026-03', 'Budget'],
    ['/accounts', 'Comptes'],
    ['/accounts/demo', 'Détail du compte'],
    ['/portfolios/demo', 'Investissements'],
    ['/life-insurance/demo', 'Assurance-vie'],
    ['/reports/annual/2026', 'Rapports'],
    ['/reports/all-years', 'Rapport pluriannuel'],
    ['/goals', 'Objectifs'],
    ['/tax/2026', 'Impôts'],
    ['/settings/profile', 'Paramètres'],
  ])('scaffolds the route %s', async (path, title) => {
    window.history.replaceState({}, '', path);
    respondWithReadyFoundation();

    renderApp();

    expect(await screen.findByRole('heading', { name: title })).toBeTruthy();
  });

  it('scaffolds application routes with client-side navigation', async () => {
    respondWithReadyFoundation();

    renderApp();
    await screen.findByRole('heading', { name: 'Synthèse' });

    fireEvent.click(screen.getAllByRole('link', { name: 'Transactions' })[0]);

    expect(screen.getByRole('heading', { name: 'Transactions' })).toBeTruthy();
    expect(window.location.pathname).toBe('/transactions');
    await waitFor(() => expect(document.title).toBe('Transactions · Cadran Budget'));
  });

  it('shows a recoverable error inside the shell for a network failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );

    renderApp();

    expect((await screen.findByRole('alert')).textContent).toContain(
      'L’API est actuellement indisponible.',
    );
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
  });
});
