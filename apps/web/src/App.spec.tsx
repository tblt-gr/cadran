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

/** Enough of a net-worth answer for the dashboard cards to render. */
const netWorth = {
  asOf: '2026-09-05',
  total: {
    value: '1000.00',
    assetCode: 'EUR',
    display: { value: '1000.00', assetCode: 'EUR' },
    belowDisplayStep: false,
  },
  reason: null,
  quality: 'CURRENT',
  stalestAgeDays: 0,
  eligibleAccountCount: 1,
  valuedAccountCount: 1,
  missingValuationCount: 0,
  staleValuationCount: 0,
  delta: {
    comparedOn: '2026-08-05',
    previousTotal: null,
    amount: null,
    amountReason: 'MISSING_VALUATION',
    rate: null,
    ratePercent: null,
    ratePercentDisplay: null,
    rateReason: 'MISSING_VALUATION',
  },
  contributions: [],
  allocation: [],
};

/**
 * The session and net-worth probes always resolve here; `status` controls the
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

      if (url.includes('/api/v1/transactions')) {
        return jsonResponse({ items: [], nextCursor: null });
      }

      if (url.includes('/api/v1/accounts')) {
        return jsonResponse({ items: [], page: 1, perPage: 50, total: 0 });
      }

      if (url.includes('/api/v1/categories')) {
        return jsonResponse({ items: [], page: 1, perPage: 50, total: 0 });
      }

      if (url.includes('/comparisons')) {
        return jsonResponse({
          planId: '00000000-0000-7000-8000-000000000001',
          period: '2026-03',
          assetCode: 'EUR',
          status: 'AVAILABLE',
          reason: null,
          comparisons: [],
        });
      }

      if (/\/api\/v1\/budget-plans\/[0-9a-f-]+$/.test(url)) {
        return jsonResponse({
          id: '00000000-0000-7000-8000-000000000001',
          periodType: 'MONTH',
          period: '2026-03',
          assetCode: 'EUR',
          state: 'DRAFT',
          version: 1,
          targets: [],
        });
      }

      if (url.includes('/api/v1/budget-plans')) {
        return jsonResponse({ items: [], page: 1, perPage: 100, total: 0 });
      }

      if (url.includes('/api/v1/reports/monthly/ledger')) {
        return jsonResponse({
          month: '2026-03',
          periodStart: '2026-03-01',
          periodEnd: '2026-03-31',
          axis: null,
          state: 'EMPTY',
          quality: 'MISSING',
          pendingCount: 0,
          closed: false,
          actionsAllowed: true,
          actionReason: null,
          incomeCategories: [],
          expenseCategories: [],
          accounts: [],
        });
      }

      if (url.includes('/api/v1/categorization-rules')) {
        return jsonResponse({ items: [], page: 1, perPage: 100, total: 0 });
      }

      if (url.includes('/api/v1/net-worth/history')) {
        return jsonResponse({ asOf: '2026-09-05', granularity: 'MONTH', points: [] });
      }

      if (url.includes('/api/v1/net-worth')) {
        return jsonResponse(netWorth);
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
    ['/budget/2026-03', 'Budget de mars 2026'],
    ['/budget/plans', 'Plans budgétaires'],
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

  it('keeps categorization rules under Categories and navigates between both screens', async () => {
    mockApi();
    window.history.replaceState({}, '', '/categories');

    renderApp();

    const categoryLinks = await screen.findAllByRole('link', { name: 'Catégories' });
    expect(categoryLinks.some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    expect(screen.queryByRole('link', { name: 'Règles de catégorisation' })).toBeNull();

    const manageRules = screen.getByRole('link', { name: 'Gérer les règles' });
    fireEvent.click(manageRules, { ctrlKey: true });
    expect(window.location.pathname).toBe('/categories');

    fireEvent.click(manageRules);

    expect(window.location.pathname).toBe('/categories/rules');
    expect(
      await screen.findAllByRole('heading', { name: 'Règles de catégorisation' }),
    ).toHaveLength(2);
    expect(screen.getByRole('link', { name: 'Retour aux catégories' })).toBeTruthy();

    const backToCategories = screen.getByRole('link', { name: 'Retour aux catégories' });
    fireEvent.click(backToCategories, { metaKey: true });
    expect(window.location.pathname).toBe('/categories/rules');

    fireEvent.click(backToCategories);

    expect(window.location.pathname).toBe('/categories');
    expect(
      await screen.findByRole('heading', { name: 'Catégories et axes analytiques' }),
    ).toBeTruthy();
  });

  it('redirects an old UUID-shaped budget link to the plan subroute', async () => {
    const id = '00000000-0000-7000-8000-000000000001';
    window.history.replaceState({}, '', `/budget/${id}`);
    mockApi();

    renderApp();

    await waitFor(() => expect(window.location.pathname).toBe(`/budget/plans/${id}`));
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
