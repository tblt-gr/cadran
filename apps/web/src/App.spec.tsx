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

function jsonResponse(body: unknown) {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

const authenticatedSession = {
  provisioned: true,
  authenticated: true,
  setupRequired: false,
  user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
  workspace: { id: 'w1', role: 'OWNER' },
};

/**
 * The session probe always resolves authenticated here; `status` controls the
 * foundation probe so the shell states can be exercised on their own.
 */
function mockApi({ status = 'ready' }: { status?: 'ready' | 'pending' | 'reject' } = {}) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: Request | string) => {
      const url = typeof input === 'string' ? input : input.url;

      if (url.includes('/api/v1/session')) {
        return jsonResponse(authenticatedSession);
      }

      if (status === 'pending') {
        return new Promise<Response>(() => undefined);
      }

      if (status === 'reject') {
        return Promise.reject(new Error('offline'));
      }

      return jsonResponse({ status: 'ready', apiVersion: 'v1' });
    }),
  );
}

describe('App', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
  });

  it('keeps the application shell visible while the foundation probe is pending', async () => {
    mockApi({ status: 'pending' });

    renderApp();

    expect(await screen.findByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
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
    mockApi();

    renderApp();

    expect(await screen.findByRole('heading', { name: title })).toBeTruthy();
  });

  it('scaffolds application routes with client-side navigation', async () => {
    mockApi();

    renderApp();
    await screen.findByRole('heading', { name: 'Synthèse' });

    fireEvent.click(screen.getAllByRole('link', { name: 'Transactions' })[0]);

    expect(screen.getByRole('heading', { name: 'Transactions' })).toBeTruthy();
    expect(window.location.pathname).toBe('/transactions');
    await waitFor(() => expect(document.title).toBe('Transactions · Cadran Budget'));
  });

  it('shows a recoverable error inside the shell for a foundation network failure', async () => {
    mockApi({ status: 'reject' });

    renderApp();

    expect((await screen.findByRole('alert')).textContent).toContain(
      'L’API est actuellement indisponible.',
    );
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
  });
});
