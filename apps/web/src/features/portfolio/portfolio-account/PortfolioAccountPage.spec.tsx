import { summaryFixture as insuranceSummaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import type { Account, PortfolioOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { positionsValuationFixture } from '@/features/portfolio/valuations/valuationFixtures';
import { cashFixture } from '@/features/portfolio/cash/cashFixtures';
import { operationFixture } from './operations/operations-table/operationFixtures';
import { summaryFixture, allocationFixture } from './overviewFixtures';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { historyFixture } from './value-history-card/valueHistoryFixtures';
import { PortfolioAccountPage } from './PortfolioAccountPage';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import { formatCalendarNumericDay } from '@/lib/decimal';

const api = vi.hoisted(() => ({
  correctPortfolioOperation: vi.fn(),
  getSession: vi.fn(),
  listInstruments: vi.fn(),
  listPortfolioOperations: vi.fn(),
  listPortfolioPositions: vi.fn(),
  listPortfolioTransferCandidates: vi.fn(),
  listPortfolioValuations: vi.fn(),
  listProducts: vi.fn(),
  readPortfolioCash: vi.fn(),
  readPortfolioPositionLots: vi.fn(),
  readLifeInsuranceContract: vi.fn(),
  readLifeInsuranceSummary: vi.fn(),
  readPortfolioSummary: vi.fn(),
  readPortfolioPerformance: vi.fn(),
  readPortfolioAllocation: vi.fn(),
  readPortfolioValuationHistory: vi.fn(),
  readPortfolioValuation: vi.fn(),
  readPortfolioValuationDraft: vi.fn(),
  previewPortfolioOperation: vi.fn(),
  readAccount: vi.fn(),
  readAccountRules: vi.fn(),
  listAccounts: vi.fn(),
  listAccountRuleOverrides: vi.fn(),
  listProductModels: vi.fn(),
  listTransactions: vi.fn(),
  readPortfolioOperation: vi.fn(),
  recordPortfolioOperation: vi.fn(),
  voidPortfolioOperation: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const accountId = '00000000-0000-7000-8000-0000000000ac';
const account = {
  id: accountId,
  label: 'PEA Démo',
  assetCode: 'EUR',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
  status: 'ACTIVE',
  institution: 'Courtier Démo',
  maskedIdentifier: null,
  productCode: null,
  productModelId: null,
  primaryGroupId: null,
  openedOn: '2024-01-15',
  closedOn: null,
  archivedAt: null,
  valuation: {
    accountId,
    requestedOn: '2026-03-10',
    asOf: null,
    amount: null,
    display: null,
    belowDisplayStep: false,
    basis: null,
    derivation: null,
    reason: null,
    source: null,
    ageDays: null,
    quality: 'MISSING',
    reconciliationStatus: null,
    snapshotId: null,
    version: null,
  },
} as Account;

function ok<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response('{}', { status }) });
}
function problem(status: number, body: object) {
  return Promise.resolve({ error: body, response: new Response('{}', { status }) });
}
function page(items: PortfolioOperation[]) {
  return ok({ items, page: 1, pageSize: 50, total: items.length });
}
/** The skeleton draws the same table: wait for it to leave before reading rows. */
async function loadedOperationsTable() {
  await waitFor(() => expect(screen.queryByTestId('operations-skeleton')).toBeNull());
  return screen.findByRole('table', { name: 'Opérations du portefeuille' });
}

function renderPage(timeZone = 'Europe/Paris', shell = false, frozen = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const session = {
    provisioned: true,
    authenticated: true,
    setupRequired: false,
    user: { id: 'user-1', email: 'owner@example.test', displayName: 'Owner' },
    workspace: { id: 'workspace-1', role: 'OWNER', timeZone, baseCurrency: 'EUR' },
  };
  client.setQueryData(['session'], session);
  api.getSession.mockImplementation(() => ok(session));
  const content = shell ? (
    <AppShell path={window.location.pathname} setPath={vi.fn()}>
      <PortfolioAccountPage accountId={accountId} />
    </AppShell>
  ) : (
    <PortfolioAccountPage accountId={accountId} />
  );
  return {
    queryClient: client,
    ...render(
      <QueryClientProvider client={client}>
        {frozen ? <FrozenQueries>{content}</FrozenQueries> : content}
      </QueryClientProvider>,
    ),
  };
}
function openAction(name: string) {
  fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
  fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
}

const deposit = operationFixture({ id: 'op-1', version: 4 });

describe('portfolio shell breadcrumb', () => {
  it('uses the page query on direct source entry, keeps tabs and forward actions', async () => {
    window.history.replaceState(
      {},
      '',
      `/portfolios/${accountId}?tab=operations&asOf=2026-03-10&operationId=op-1`,
    );
    renderPage('Europe/Paris', true);
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    const current = await within(nav).findByText(account.label);
    expect(current.getAttribute('aria-current')).toBe('page');
    expect(within(nav).getByRole('link', { name: 'Investissements' }).getAttribute('href')).toBe(
      '/portfolios',
    );
    expect(screen.getByRole('heading', { level: 1, name: account.label })).toBeTruthy();
    expect(api.readAccount).toHaveBeenCalledTimes(1);
    expect(window.location.search).toBe('?tab=operations&asOf=2026-03-10&operationId=op-1');
    expect(screen.queryByRole('link', { name: 'Voir le compte' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Gérer les instruments' }).getAttribute('href')).toBe(
      '/portfolios/instruments',
    );
    expect(screen.getByRole('link', { name: 'Opérations' }).getAttribute('aria-current')).toBe(
      'page',
    );
  });

  it.each([403, 404, 500])(
    'clears a cached label after a failed account read (%s)',
    async (status) => {
      const { queryClient } = renderPage('Europe/Paris', true);
      const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
      await within(nav).findByText(account.label);
      api.readAccount.mockImplementation(() => problem(status, {}));
      void queryClient.invalidateQueries({ queryKey: ['account', accountId] });
      await within(nav).findByText('Compte');
      expect(within(nav).queryByText(account.label)).toBeNull();
      expect(screen.getByRole('alert')).toBeTruthy();
    },
  );

  it('renders the real static hierarchy under FrozenQueries without fetching', () => {
    renderPage('Europe/Paris', true, true);
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    expect(within(nav).getByRole('link', { name: 'Investissements' }).getAttribute('href')).toBe(
      '/portfolios',
    );
    expect(within(nav).getByText('Chargement du compte').getAttribute('aria-busy')).toBe('true');
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(api.readAccount).not.toHaveBeenCalled();
    expect(api.readPortfolioSummary).not.toHaveBeenCalled();
  });
});

beforeEach(() => {
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=operations`);
  api.listTransactions.mockImplementation(() =>
    ok({ items: [], nextCursor: null, hasMore: false, pageSize: 50 }),
  );
  api.listAccounts.mockImplementation(() =>
    ok({ items: [account], page: 1, pageSize: 100, total: 1 }),
  );
  api.listAccountRuleOverrides.mockImplementation(() => ok({ overrides: [] }));
  api.listProductModels.mockImplementation(() => ok({ items: [] }));
  api.readAccountRules.mockImplementation(() => problem(404, { code: 'not_found' }));
  api.readPortfolioSummary.mockImplementation(() => ok(summaryFixture()));
  api.readLifeInsuranceSummary.mockImplementation(() => ok(insuranceSummaryFixture()));
  api.readPortfolioAllocation.mockImplementation(() => ok(allocationFixture()));
  api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
  api.readPortfolioValuationHistory.mockImplementation(() => ok(historyFixture()));
  api.readAccount.mockImplementation(() => ok(account));
  api.listProducts.mockImplementation(() => ok({ items: [], page: 1, pageSize: 100, total: 0 }));
  api.listInstruments.mockImplementation(() => ok({ items: [], page: 1, pageSize: 20, total: 0 }));
  api.listPortfolioTransferCandidates.mockImplementation(() => ok({ items: [] }));
  api.previewPortfolioOperation.mockImplementation(() =>
    ok({
      cashAmount: { value: '1000', assetCode: 'EUR' },
      roundingResidual: null,
      expectedGrossAmount: null,
    }),
  );
  api.listPortfolioOperations.mockImplementation(() => page([deposit]));
  api.listPortfolioPositions.mockImplementation(() => ok({ asOf: '2026-03-10', items: [] }));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PortfolioAccountPage', () => {
  it.each([
    ['operation', 'Escape'],
    ['operation', 'button'],
    ['valuation', 'Escape'],
    ['valuation', 'button'],
  ] as const)(
    'dismisses a deliberate %s deep link with %s without reopening it after refresh',
    async (kind, closing) => {
      window.history.replaceState(
        { kept: true },
        '',
        `/portfolios/${accountId}?tab=${kind === 'operation' ? 'operations' : 'valuations'}&asOf=2026-03-10&operation=${deposit.id}&valuation=valuation-1&filter=kept#sources`,
      );
      api.listPortfolioValuations.mockImplementation(() =>
        ok({ items: [], page: 1, pageSize: 24, total: 0 }),
      );
      api.readPortfolioOperation.mockImplementation(() => ok(deposit));
      api.readPortfolioValuation.mockImplementation(() =>
        ok(positionsValuationFixture({ id: 'valuation-1' })),
      );
      const view = renderPage();
      await screen.findByRole('dialog', {
        name: kind === 'operation' ? /Opération : Versement/ : 'Valorisation du 10/03/2026',
      });
      const entries = window.history.length;
      if (closing === 'Escape') fireEvent.keyDown(document, { key: 'Escape' });
      else fireEvent.click(screen.getByRole('button', { name: 'Fermer' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
      const params = new URLSearchParams(window.location.search);
      expect(params.has('operation')).toBe(false);
      expect(params.has('valuation')).toBe(false);
      expect(params.get('tab')).toBe(kind === 'operation' ? 'operations' : 'valuations');
      expect(params.get('asOf')).toBe('2026-03-10');
      expect(params.get('filter')).toBe('kept');
      expect(window.location.hash).toBe('#sources');
      expect(window.history.length).toBe(entries);
      expect(window.history.state).toEqual({ kept: true });
      fireEvent(window, new PopStateEvent('popstate'));
      expect(screen.queryByRole('dialog')).toBeNull();
      view.unmount();
      renderPage();
      await waitFor(() => expect(screen.queryByTestId('value-history-skeleton')).toBeNull());
      expect(screen.queryByRole('dialog')).toBeNull();
    },
  );
  it.each(['valuation'] as const)(
    'clears stale %s selectors when changing Au and keeps browser back coherent',
    async (kind) => {
      const tab = 'valuations';
      window.history.replaceState(
        {},
        '',
        `/portfolios/${accountId}?tab=${tab}&asOf=2026-03-10&operation=${deposit.id}&valuation=valuation-1&filter=kept#sources`,
      );
      api.listPortfolioValuations.mockImplementation(() =>
        ok({ items: [], page: 1, pageSize: 24, total: 0 }),
      );
      api.readPortfolioOperation.mockImplementation(() => ok(deposit));
      api.readPortfolioValuation.mockImplementation(() =>
        ok(positionsValuationFixture({ id: 'valuation-1' })),
      );
      const view = renderPage();
      await screen.findByRole('dialog', {
        name: 'Valorisation du 10/03/2026',
      });
      fireEvent.change(screen.getAllByLabelText('Situation au')[0]!, {
        target: { value: '2026-03-09' },
      });
      await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
      const params = new URLSearchParams(window.location.search);
      expect(params.has('operation')).toBe(false);
      expect(params.has('valuation')).toBe(false);
      expect(params.get('asOf')).toBe('2026-03-09');
      expect(params.get('tab')).toBe(tab);
      expect(params.get('filter')).toBe('kept');
      expect(window.location.hash).toBe('#sources');
      window.history.back();
      await waitFor(() =>
        expect(new URLSearchParams(window.location.search).get('asOf')).toBe('2026-03-10'),
      );
      expect(new URLSearchParams(window.location.search).has(kind)).toBe(false);
      expect(screen.queryByRole('dialog')).toBeNull();
      view.unmount();
      renderPage();
      await waitFor(() => expect(screen.queryByTestId('value-history-skeleton')).toBeNull());
      expect(screen.queryByRole('dialog')).toBeNull();
    },
  );

  it('keeps dated complete values while explicitly warning about a newer partial valuation', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    window.history.replaceState({}, '', `/portfolios/${accountId}?asOf=2026-10-03&tab=account`);
    const summary = summaryFixture();
    summary.asOf = '2026-10-03';
    summary.valuation = { ...summary.valuation!, valuedOn: '2026-09-30' };
    summary.newerPartialValuation = {
      ...summary.valuation,
      id: 'partial-1',
      valuedOn: '2026-10-03',
      completeness: 'PARTIAL',
      totalValue: null,
    };
    summary.reconciliation = {
      status: 'NEEDS_REVIEW',
      warnings: [{ code: 'PARTIAL_VALUATION', count: 1 }],
    };
    summary.explanations.TOTAL_VALUE = {
      ...summary.explanations.TOTAL_VALUE,
      period: { from: '2026-09-30', to: '2026-09-30' },
      sources: [{ kind: 'VALUATION', id: summary.valuation.id, date: '2026-09-30' }],
    };
    api.readPortfolioSummary.mockImplementation(() => ok(summary));
    api.readPortfolioAllocation.mockImplementation(() =>
      ok({
        ...allocationFixture(),
        asOf: summary.asOf,
        valuation: summary.valuation,
        newerPartialValuation: summary.newerPartialValuation,
      }),
    );
    renderPage();
    expect(
      await screen.findByText(
        'Valorisation du 03/10/2026 partielle : valeurs du 30/09/2026 affichées.',
      ),
    ).toBeTruthy();
    expect(screen.getByText(/Gain \+121,42/)).toBeTruthy();
    expect(screen.queryByText('au 30/09/2026, au prix de revient moyen')).toBeNull();
    expect(screen.getByText('À vérifier (1)')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Valeur totale' }));
    expect(screen.getByText('Du 30/09/2026 au 30/09/2026')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Valorisation du 30/09/2026' })).toBeTruthy();
  });
  it('retains explicit non-calculable states when only a partial valuation exists', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    const summary = summaryFixture();
    summary.valuation = { ...summary.valuation!, completeness: 'PARTIAL', totalValue: null };
    summary.totalValue = null;
    summary.unrealizedGain = {
      value: null,
      securitiesValue: null,
      costBasis: null,
      reason: 'PARTIAL_VALUATION',
    };
    api.readPortfolioSummary.mockImplementation(() => ok(summary));
    api.readPortfolioAllocation.mockImplementation(() =>
      ok({
        ...allocationFixture(),
        valuation: summary.valuation,
        reason: 'PARTIAL_VALUATION',
        basis: null,
        slices: [],
      }),
    );
    renderPage();
    expect(await screen.findByText('Non valorisé')).toBeTruthy();
    expect(screen.queryByText(/partielle : valeurs du/)).toBeNull();
  });
  it('shows no newer-partial warning when the server reports none for a past date', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    renderPage();
    await screen.findByText(/Gain/);
    expect(screen.queryByText(/partielle : valeurs du/)).toBeNull();
  });

  it('shows one reminder per due month and opens the valuation modal from it', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    api.readPortfolioSummary.mockImplementation(() =>
      ok({ ...summaryFixture(), dueMonths: ['2026-02', '2026-03'] }),
    );
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    api.readPortfolioValuationDraft.mockImplementation(() =>
      ok({
        valuedOn: '2026-02-28',
        derivedCash: { value: '100', assetCode: 'EUR' },
        suggestedDetail: 'TOTAL',
        lastTotal: null,
        currentValuation: null,
        positions: [],
      }),
    );
    renderPage();
    expect(await screen.findByText('Valorisation de fin février à saisir')).toBeTruthy();
    expect(screen.getByText('Valorisation de fin mars à saisir')).toBeTruthy();
    fireEvent.click(screen.getAllByRole('link', { name: 'Saisir la valorisation' })[0]!);
    const dialog = await screen.findByRole('dialog');
    await waitFor(() => expect(screen.queryByTestId('valuation-draft-skeleton')).toBeNull());
    await within(dialog).findByText(/retenir les espèces calculées/);
    expect(api.readPortfolioValuationDraft.mock.calls[0]![0].query.valuedOn).toBe('2026-02-28');
  });

  it('propagates the selected date to every read and keeps it when switching tabs', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?asOf=2026-03-10&tab=positions`);
    api.readPortfolioCash.mockImplementation(() => ok(cashFixture()));
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    renderPage();
    await screen.findByText('Aucune position à cette date');
    fireEvent.change(screen.getByLabelText('Situation au'), { target: { value: '2026-02-28' } });

    expect(api.listPortfolioPositions.mock.calls.at(-1)![0].query.asOf).toBe('2026-02-28');
    await waitFor(() =>
      expect(api.readPortfolioAllocation.mock.calls.at(-1)![0].query.asOf).toBe('2026-02-28'),
    );
    fireEvent.click(screen.getByRole('link', { name: 'Opérations' }));
    await loadedOperationsTable();
    expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.to).toBeUndefined();
    fireEvent.click(screen.getByRole('link', { name: 'Valorisations' }));
    await screen.findByText(/Saisissez la valeur du compte/);
    expect(api.listPortfolioValuations.mock.calls.at(-1)![0].query.to).toBe('2026-02-28');
    expect(new URLSearchParams(window.location.search).get('asOf')).toBe('2026-02-28');
  });
  it('opens an operation source from its explanation and restores keyboard focus', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    const summary = summaryFixture();
    summary.explanations.CASH = {
      ...summary.explanations.CASH,
      sources: [{ kind: 'OPERATION', id: deposit.id, date: deposit.tradeDate }],
    };
    api.readPortfolioSummary.mockImplementation(() => ok(summary));
    api.readPortfolioOperation.mockImplementation(() => ok(deposit));
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Expliquer : Espèces' }).matches(':disabled')).toBe(
        false,
      ),
    );
    const info = screen.getByRole('button', { name: 'Expliquer : Espèces' });
    info.focus();
    fireEvent.click(info);
    fireEvent.click(
      screen.getByRole('button', {
        name: `Opération du ${formatCalendarNumericDay(deposit.tradeDate)}`,
      }),
    );
    expect(await screen.findByRole('dialog', { name: /Opération : Versement/ })).toBeTruthy();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() =>
      expect(document.activeElement).toBe(
        screen.getByRole('link', {
          name:
            new URLSearchParams(window.location.search).get('tab') === 'operations'
              ? 'Opérations'
              : 'Valorisations',
        }),
      ),
    );
  });

  it('opens the exact valuation source from a KPI explanation with one dialog', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    api.readPortfolioValuation.mockImplementation(() =>
      ok(positionsValuationFixture({ id: 'valuation-1' })),
    );
    renderPage();
    await waitFor(() =>
      expect(
        screen.getByRole('button', { name: 'Expliquer : Valeur totale' }).matches(':disabled'),
      ).toBe(false),
    );
    const info = screen.getByRole('button', { name: 'Expliquer : Valeur totale' });
    info.focus();
    fireEvent.click(info);
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 10/03/2026' }));
    expect(await screen.findByRole('dialog', { name: 'Valorisation du 10/03/2026' })).toBeTruthy();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    expect(api.readPortfolioValuation.mock.calls.at(-1)![0].path.id).toBe('valuation-1');
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() =>
      expect(document.activeElement).toBe(
        screen.getByRole('link', {
          name:
            new URLSearchParams(window.location.search).get('tab') === 'operations'
              ? 'Opérations'
              : 'Valorisations',
        }),
      ),
    );
  });

  it('redirects the former cash tab to the operations loading layout', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=cash`);
    api.readAccount.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('link', { name: 'Opérations' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(screen.queryByRole('link', { name: 'Espèces' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Contrôles d’espèces' })).toBeNull();
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });

  it('shows the Valorisations layout, real chrome included, while the account loads', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=valuations`);
    api.readAccount.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('link', { name: 'Valorisations' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(screen.getByRole('button', { name: 'Nouvelle valorisation' }).matches(':disabled')).toBe(
      true,
    );
    expect(screen.getByRole('columnheader', { name: 'Complétude' })).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });

  it('orders the tabs and opens Compte by default', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}`);
    renderPage();

    await screen.findByRole('heading', { level: 3, name: account.label });
    const nav = screen.getByRole('navigation', { name: 'Sections du portefeuille' });
    expect(
      within(nav)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual(['Compte', 'Opérations', 'Valorisations', 'Positions', 'Performance']);
    expect(within(nav).getByRole('link', { name: 'Compte' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(screen.queryByRole('heading', { name: 'Mouvements' })).toBeNull();
  });

  it('disables both entry actions on an account that is not active', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    api.readAccount.mockImplementation(() => ok({ ...account, status: 'CLOSED' }));
    renderPage();
    await screen.findByText(/Gain/);
    for (const name of ['Nouvelle valorisation', 'Nouvelle opération'])
      expect(screen.getByRole('button', { name }).matches(':disabled')).toBe(true);
  });

  it('answers a former allocation link with Positions and retries a failed allocation', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=allocation`);
    api.readPortfolioAllocation.mockImplementationOnce(() => problem(500, {}));
    renderPage();

    expect(await screen.findByText('La répartition est indisponible')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('heading', { name: 'Répartition' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Positions' }).getAttribute('aria-current')).toBe(
      'page',
    );
  });

  it('opens a new valuation from the header on the Valorisations tab', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=positions`);
    api.readPortfolioSummary.mockImplementation(() =>
      ok({ ...summaryFixture(), dueMonths: ['2026-02'] }),
    );
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    renderPage();
    await screen.findByText('Aucune position à cette date');
    await waitFor(() =>
      expect(
        screen.getByRole('button', { name: 'Nouvelle valorisation' }).matches(':disabled'),
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle valorisation' }));

    expect(new URLSearchParams(window.location.search).get('tab')).toBe('valuations');
    const dialog = await screen.findByRole('dialog', { name: 'Nouvelle valorisation' });
    expect((within(dialog).getByLabelText('Date de valorisation') as HTMLInputElement).value).toBe(
      '2026-02-28',
    );
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('opens the Valorisations tab from ?tab=valuations', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=valuations`);
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    renderPage();

    expect(await screen.findByText(/Saisissez la valeur du compte/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Valorisations' }).getAttribute('aria-current')).toBe(
      'page',
    );
  });

  it('opens operations from a former cash link', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=cash`);
    api.readPortfolioCash.mockImplementation(() => ok(cashFixture()));
    renderPage();

    await loadedOperationsTable();
    expect(screen.getByRole('link', { name: 'Opérations' }).getAttribute('aria-current')).toBe(
      'page',
    );
  });

  it('switches from the positions tab before opening the global create action', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=positions`);
    renderPage();

    await screen.findByText('Aucune position à cette date');
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle opération' }));

    expect(new URLSearchParams(window.location.search).get('tab')).toBe('operations');
    expect(new URLSearchParams(window.location.search).has('asOf')).toBe(true);
    expect(screen.getByRole('link', { name: 'Opérations' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(await screen.findByRole('dialog', { name: 'Nouvelle opération' })).toBeTruthy();
  });

  it('uses the workspace day in the positions loading toolbar and live query', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-03-31T22:30:00Z'));
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=positions`);
    let resolveAccount: ((value: unknown) => void) | undefined;
    api.readAccount.mockImplementation(() => new Promise((done) => (resolveAccount = done)));

    try {
      renderPage('Pacific/Auckland');
      const loadingDate = screen.getAllByLabelText('Situation au').at(-1) as HTMLInputElement;
      expect(loadingDate.value).toBe('2026-04-01');

      resolveAccount?.(await ok(account));
      expect(await screen.findByText('Aucune position à cette date')).toBeTruthy();
      expect(api.listPortfolioPositions.mock.calls.at(-1)![0].query.asOf).toBe('2026-04-01');
    } finally {
      vi.useRealTimers();
    }
  });

  it('shows the real page chrome with a disabled action while loading, then the operations', async () => {
    let resolve: ((value: unknown) => void) | undefined;
    api.readAccount.mockImplementation(() => new Promise((done) => (resolve = done)));
    renderPage();
    expect(screen.getByTestId('operations-skeleton')).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Nouvelle opération' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(screen.getByRole('link', { name: 'Opérations' })).toBeTruthy();
    expect(
      (
        screen.getByRole('link', { name: 'Gérer les instruments' }) as HTMLAnchorElement
      ).getAttribute('href'),
    ).toBe('/portfolios/instruments');
    expect(
      within(screen.getByTestId('operations-skeleton')).getAllByRole('columnheader', {
        name: 'Date',
      }),
    ).toHaveLength(1);
    expect(screen.queryByTestId('value-history-skeleton')).toBeNull();
    resolve?.(await ok(account));
    expect(await loadedOperationsTable()).toBeTruthy();
    expect(screen.queryByTestId('operations-skeleton')).toBeNull();
    expect(screen.queryByRole('link', { name: 'Voir le compte' })).toBeNull();
    expect(
      (
        screen.getByRole('link', { name: 'Gérer les instruments' }) as HTMLAnchorElement
      ).getAttribute('href'),
    ).toBe('/portfolios/instruments');
  });

  it('answers a missing account with the not found state', async () => {
    api.readAccount.mockImplementation(() => problem(404, { type: '/problems/not-found' }));
    renderPage();
    expect(await screen.findByText('Compte introuvable')).toBeTruthy();
  });

  it('explains that a non portfolio account is not tracked here', async () => {
    api.readAccount.mockImplementation(() => ok({ ...account, valuationMode: 'SNAPSHOTS' }));
    renderPage();
    expect(await screen.findByText('Ce compte n’est pas suivi en mode portefeuille')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ouvrir le compte' })).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Nouvelle opération' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });

  it('maps a 422 account_not_portfolio from the list to the same state', async () => {
    api.listPortfolioOperations.mockImplementation(() =>
      problem(422, { code: 'account_not_portfolio', type: '/problems/x' }),
    );
    renderPage();
    expect(await screen.findByText('Ce compte n’est pas suivi en mode portefeuille')).toBeTruthy();
  });

  it('opens an insurance portfolio and blocks creation until its contract exists', async () => {
    api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
    api.readLifeInsuranceContract.mockImplementation(() =>
      ok({ contract: null, feeTerms: [], euroFundRates: [], euroFunds: [] }),
    );
    api.listPortfolioOperations.mockImplementation(() => page([]));
    renderPage();
    await screen.findByText('Aucune opération');
    expect(
      (screen.getByRole('button', { name: 'Nouvelle opération' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (
        screen.getByRole('button', {
          name: 'Enregistrer la première opération',
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
    expect(screen.getByRole('link', { name: 'Supports' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Liquidités' })).toBeNull();
    expect(
      screen.getByRole('link', { name: "Renseignez d'abord le contrat" }).getAttribute('href'),
    ).toContain('tab=contract');
    fireEvent.click(screen.getByRole('link', { name: 'Contrat' }));
    expect(await screen.findByRole('button', { name: 'Renseigner le contrat' })).toBeTruthy();
  });

  it('shows identity without duplicate bank movements on Compte', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    renderPage();
    expect(await screen.findByRole('heading', { level: 3, name: account.label })).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Mouvements' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Voir les règles' })).toBeTruthy();
  });

  it('reads a former contract link of a securities account as its account tab', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=contract`);
    renderPage();
    await screen.findByRole('heading', { level: 3, name: account.label });
    const tab = screen.getByRole('link', { name: 'Compte' });
    expect(tab.getAttribute('aria-current')).toBe('page');
    expect(screen.queryByRole('link', { name: 'Contrat' })).toBeNull();
  });

  it('keeps the account summary to the value, the unrealized gain and the cash', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
    renderPage();
    const summary = await screen.findByRole('region', { name: 'Indicateurs du portefeuille' });
    expect(
      within(summary)
        .getAllByRole('heading', { level: 3 })
        .map((heading) => heading.textContent),
    ).toEqual(['Valeur totale', 'Plus-value latente', 'Espèces']);
    expect(within(summary).queryByRole('region', { name: 'Depuis le premier relevé' })).toBeNull();
  });

  it('shows an empty state with a create action', async () => {
    api.listPortfolioOperations.mockImplementation(() => page([]));
    renderPage();
    expect(await screen.findByText('Aucune opération')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la première opération' }));
    expect(await screen.findByRole('dialog', { name: 'Nouvelle opération' })).toBeTruthy();
  });

  it('offers day reordering when a sibling operation is hidden from the current list', async () => {
    const operation = operationFixture({
      id: 'op-reorderable',
      activeDayOperationCount: 2,
    });
    api.listPortfolioOperations.mockImplementation(() => page([operation]));
    renderPage();

    await loadedOperationsTable();
    expect(api.listPortfolioOperations).toHaveBeenCalledTimes(1);
    openAction('Réordonner la journée');
    expect(screen.getByRole('dialog', { name: /Réordonner la journée/ })).toBeTruthy();
  });

  it('shows an error state and retries', async () => {
    api.listPortfolioOperations.mockImplementationOnce(() => problem(500, {}));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
    expect(await loadedOperationsTable()).toBeTruthy();
  });

  it('asks for voided operations only when the toggle is on', async () => {
    renderPage();
    await loadedOperationsTable();
    expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.includeVoided).toBe(false);
    fireEvent.click(screen.getByLabelText('Afficher les opérations annulées'));
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.includeVoided).toBe(true),
    );
  });

  it('shows only the operations period, without a hidden situation-date limit', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=operations&asOf=2026-02-01`);
    renderPage();
    await loadedOperationsTable();
    expect(screen.queryByLabelText('Situation au')).toBeNull();
    expect(screen.getAllByLabelText('Au')).toHaveLength(1);
    fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-03-31' } });
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.to).toBe('2026-03-31'),
    );
  });

  it('filters the period with native date inputs', async () => {
    renderPage();
    await loadedOperationsTable();
    expect((screen.getByLabelText('Du') as HTMLInputElement).type).toBe('date');
    expect((screen.getAllByLabelText('Au').at(-1) as HTMLInputElement).type).toBe('date');
    expect(screen.queryByRole('button', { name: 'Effacer' })).toBeNull();
    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-01-01' } });
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.from).toBe('2026-01-01'),
    );
    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '' } });
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.from).toBeUndefined(),
    );
  });

  it('filters by type', async () => {
    renderPage();
    await loadedOperationsTable();
    fireEvent.click(screen.getByRole('button', { name: 'Types d’opération Tous les types' }));
    fireEvent.click(screen.getByRole('option', { name: 'Achat' }));
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.type).toEqual(['BUY']),
    );
  });

  it('records a deposit with an idempotency key and refreshes the list', async () => {
    api.recordPortfolioOperation.mockImplementation(() => ok(deposit, 201));
    renderPage();
    await loadedOperationsTable();
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle opération' }));
    fireEvent.change(await screen.findByLabelText('Montant brut'), { target: { value: '1000' } });
    const calls = api.listPortfolioOperations.mock.calls.length;
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.recordPortfolioOperation).toHaveBeenCalled());
    const call = api.recordPortfolioOperation.mock.calls[0]![0];
    expect(call.path).toEqual({ accountId });
    expect(call.body.grossAmount).toEqual({ value: '1000', assetCode: 'EUR' });
    expect(call.headers['Idempotency-Key']).toMatch(/[0-9a-f-]{36}/);
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.length).toBeGreaterThan(calls),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('shows the exact expected gross amount when the server refuses a mismatch', async () => {
    api.recordPortfolioOperation.mockImplementation(() =>
      problem(422, {
        code: 'operation_amount_mismatch',
        expectedGrossAmount: '2305.688',
        type: '/p',
      }),
    );
    renderPage();
    await loadedOperationsTable();
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle opération' }));
    fireEvent.change(await screen.findByLabelText('Montant brut'), {
      target: { value: '2305.70' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect((await screen.findAllByText(/2305\.688/)).length).toBeGreaterThan(0);
  });

  it('modifies an operation in one step through the correct endpoint', async () => {
    api.correctPortfolioOperation.mockImplementation(() => ok(deposit, 201));
    renderPage();
    await loadedOperationsTable();
    openAction('Modifier');
    const amount = (await screen.findByLabelText('Montant brut')) as HTMLInputElement;
    expect(amount.value).toBe('1000');
    fireEvent.change(amount, { target: { value: '1200' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.correctPortfolioOperation).toHaveBeenCalled());
    const call = api.correctPortfolioOperation.mock.calls[0]![0];
    expect(call.path).toEqual({ id: 'op-1' });
    expect(call.body.version).toBe(4);
    expect(call.body.reason).toBeNull();
    expect(call.body.operation.grossAmount.value).toBe('1200');
  });

  it('voids an operation with a required reason', async () => {
    api.voidPortfolioOperation.mockImplementation(() => ok(deposit));
    renderPage();
    await loadedOperationsTable();
    openAction('Annuler');
    fireEvent.change(await screen.findByLabelText('Motif'), { target: { value: 'Doublon' } });
    fireEvent.click(screen.getByRole('button', { name: 'Annuler l’opération' }));
    await waitFor(() => expect(api.voidPortfolioOperation).toHaveBeenCalled());
    expect(api.voidPortfolioOperation.mock.calls[0]![0].body).toEqual({
      version: 4,
      reason: 'Doublon',
    });
  });

  it('reloads the list and tells the user when the version is stale', async () => {
    api.voidPortfolioOperation.mockImplementation(() =>
      problem(409, { code: 'stale_operation_version', type: '/p' }),
    );
    renderPage();
    await loadedOperationsTable();
    openAction('Annuler');
    fireEvent.change(await screen.findByLabelText('Motif'), { target: { value: 'Doublon' } });
    fireEvent.click(screen.getByRole('button', { name: 'Annuler l’opération' }));
    expect(await screen.findByText(/a déjà été modifiée/)).toBeTruthy();
  });

  it('opens the detail with the link to the replaced operation', async () => {
    const replaced = operationFixture({
      id: 'op-2',
      state: 'VOIDED',
      voidKind: 'REPLACED',
      correctedByOperationId: 'op-1',
    });
    api.listPortfolioOperations.mockImplementation(() => page([replaced]));
    api.readPortfolioOperation.mockImplementation((options: { path: { id: string } }) =>
      ok(
        options.path.id === 'op-2'
          ? replaced
          : operationFixture({ id: 'op-1', tradeDate: '2026-03-05', correctsOperationId: 'op-2' }),
      ),
    );
    renderPage();
    fireEvent.click(await screen.findByLabelText('Afficher les opérations annulées'));
    await screen.findByText('Remplacée');
    openAction('Détail');
    expect(
      await screen.findByRole('button', { name: 'Remplacée par l’opération du 05/03/2026' }),
    ).toBeTruthy();
  });
});

it('composes insurance figures, history and performance instead of securities KPIs', async () => {
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account`);
  api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
  api.readLifeInsuranceContract.mockImplementation(() =>
    ok({
      contract: insuranceSummaryFixture().contract,
      feeTerms: [],
      euroFundRates: [],
      euroFunds: [],
    }),
  );
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=account&asOf=2026-03-10`);
  renderPage();
  await waitFor(() => expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull());
  expect(await screen.findByRole('heading', { name: 'Valeur du contrat' })).toBeTruthy();
  expect(screen.queryByRole('heading', { name: 'Suivi fiscal (informatif)' })).toBeNull();
  expect(screen.queryByRole('heading', { name: "Depuis l'ouverture" })).toBeNull();
  expect(screen.queryByRole('region', { name: 'Indicateurs du portefeuille' })).toBeNull();
  expect(api.readPortfolioValuationHistory).toHaveBeenCalled();
  expect(api.readLifeInsuranceSummary).toHaveBeenCalledWith(
    expect.objectContaining({ query: { asOf: '2026-03-10' } }),
  );
});

it('restores focus to the exact quality source when two issues link to the same operation', async () => {
  const summary = insuranceSummaryFixture();
  summary.qualityIssues = [
    { code: 'INTEREST_BASIS_UNKNOWN', operationIds: [deposit.id], count: 1 },
    { code: 'MISSING_EURO_FUND_RATE', operationIds: [deposit.id], count: 1 },
  ];
  api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
  api.readLifeInsuranceSummary.mockImplementation(() => ok(summary));
  api.readLifeInsuranceContract.mockImplementation(() =>
    ok({ contract: summary.contract, feeTerms: [], euroFundRates: [], euroFunds: [] }),
  );
  api.readPortfolioOperation.mockImplementation(() => ok(deposit));
  window.history.replaceState(
    {},
    '',
    `/portfolios/${accountId}?tab=valuations&asOf=${summary.asOf}`,
  );
  renderPage();
  await waitFor(() => expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull());
  const qualitySection = () =>
    screen.getByRole('heading', { name: 'Qualité des données' }).closest('section')!;
  await screen.findByRole('heading', { name: 'Qualité des données' });
  const links = await within(qualitySection()).findAllByRole('link', { name: 'Opération 1' });
  expect(links).toHaveLength(2);
  expect(links[1].getAttribute('href')).toBe(links[0].getAttribute('href'));
  links[1].focus();
  fireEvent.click(links[1]);
  await screen.findByRole('dialog', { name: /Opération : Versement/ });
  expect(links[1].isConnected).toBe(false);
  fireEvent.keyDown(document, { key: 'Escape' });
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  await waitFor(() =>
    expect(new URLSearchParams(window.location.search).get('tab')).toBe('valuations'),
  );
  await waitFor(() => {
    const returnedLinks = within(qualitySection()).getAllByRole('link', { name: 'Opération 1' });
    expect(document.activeElement).toBe(returnedLinks[1]);
    expect(document.activeElement).not.toBe(returnedLinks[0]);
  });
});

it.each(['quality', 'cumulative'] as const)(
  'restores focus to a %s source link after previously dismissing a KPI explanation',
  async (kind) => {
    const summary = insuranceSummaryFixture();
    summary.qualityIssues = [
      { code: 'INTEREST_BASIS_UNKNOWN', operationIds: [deposit.id], count: 1 },
    ];
    summary.cumulative.sources = [{ kind: 'OPERATION', id: deposit.id, date: deposit.tradeDate }];
    api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
    api.readLifeInsuranceSummary.mockImplementation(() => ok(summary));
    api.readLifeInsuranceContract.mockImplementation(() =>
      ok({ contract: summary.contract, feeTerms: [], euroFundRates: [], euroFunds: [] }),
    );
    api.readPortfolioOperation.mockImplementation(() => ok(deposit));
    window.history.replaceState(
      {},
      '',
      `/portfolios/${accountId}?tab=${kind === 'quality' ? 'valuations' : 'contract'}&asOf=${summary.asOf}`,
    );
    renderPage();
    await screen.findByRole('heading', {
      name: kind === 'quality' ? 'Qualité des données' : 'Montants cumulés enregistrés',
    });
    await waitFor(() =>
      expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull(),
    );
    await waitFor(() =>
      expect(
        screen
          .getByRole('heading', {
            name: kind === 'quality' ? 'Qualité des données' : 'Montants cumulés enregistrés',
          })
          .closest('section')
          ?.getAttribute('aria-busy'),
      ).not.toBe('true'),
    );
    const section = screen
      .getByRole('heading', {
        name: kind === 'quality' ? 'Qualité des données' : 'Montants cumulés enregistrés',
      })
      .closest('section')!;
    if (kind === 'cumulative') {
      const disclosure = within(section)
        .getByText('Sources des montants cumulés')
        .closest('details')!;
      expect(disclosure.open).toBe(false);
      fireEvent.click(disclosure.querySelector('summary')!);
      await waitFor(() => expect(disclosure.open).toBe(true));
    }
    const link = await within(section).findByRole('link', {
      name: kind === 'quality' ? 'Opération 1' : /Opération du/,
    });
    link.focus();
    fireEvent.click(link);
    await screen.findByRole('dialog', { name: /Opération : Versement/ });
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() =>
      expect(new URLSearchParams(window.location.search).get('tab')).toBe(
        kind === 'quality' ? 'valuations' : 'contract',
      ),
    );
    await waitFor(() =>
      expect(document.activeElement?.getAttribute('href')).toBe(link.getAttribute('href')),
    );
    if (kind === 'cumulative') {
      expect(document.activeElement?.closest('details')?.open).toBe(true);
      expect(document.activeElement).not.toBe(link);
      const returnedSection = screen
        .getByRole('heading', { name: 'Montants cumulés enregistrés' })
        .closest('section')!;
      expect(document.activeElement).toBe(
        within(returnedSection).getByRole('link', { name: /Opération du/ }),
      );
    }
  },
);

it.each(['performance', 'operations', 'positions', 'valuations', 'contract'] as const)(
  'renders insurance details only in their corresponding %s tab',
  async (tab) => {
    api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
    api.readLifeInsuranceContract.mockImplementation(() =>
      ok({
        contract: insuranceSummaryFixture().contract,
        feeTerms: [],
        euroFundRates: [],
        euroFunds: [],
      }),
    );
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=${tab}&asOf=2026-03-10`);
    renderPage();
    await waitFor(() =>
      expect(
        screen.getByRole('button', { name: 'Nouvelle valorisation' }).matches(':disabled'),
      ).toBe(false),
    );
    const expected = {
      positions: 'Supports du contrat',
      valuations: 'Qualité des données',
      contract: 'Montants cumulés enregistrés',
    };
    const nav = screen.getByRole('navigation', { name: 'Sections du portefeuille' });
    for (const [destination, heading] of Object.entries(expected)) {
      if (tab === destination) {
        const title = await screen.findByRole('heading', { name: heading });
        expect(nav.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      } else expect(screen.queryByRole('heading', { name: heading })).toBeNull();
    }
    if (tab === 'positions') {
      expect(screen.getByRole('heading', { name: 'Répartition' })).toBeTruthy();
      expect(screen.getByText('Quantités et lots issus des opérations')).toBeTruthy();
      expect(screen.getByLabelText('Afficher les positions soldées')).toBeTruthy();
    }
    if (tab === 'contract')
      expect(screen.getByRole('heading', { name: 'Suivi fiscal (informatif)' })).toBeTruthy();
  },
);

it.each(['positions', 'valuations'] as const)(
  'keeps the securities %s tab free of insurance reports',
  async (tab) => {
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, pageSize: 24, total: 0 }),
    );
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=${tab}&asOf=2026-03-10`);
    renderPage();
    await waitFor(() =>
      expect(
        screen.getByRole('button', { name: 'Nouvelle valorisation' }).matches(':disabled'),
      ).toBe(false),
    );
    if (tab === 'positions') {
      expect(await screen.findByLabelText('Afficher les positions soldées')).toBeTruthy();
      expect(await screen.findByRole('button', { name: 'Classe d’actifs' })).toBeTruthy();
    }
    if (tab === 'valuations')
      expect(await screen.findByText(/Saisissez la valeur du compte/)).toBeTruthy();
    expect(api.readLifeInsuranceSummary).not.toHaveBeenCalled();
    expect(screen.queryByRole('heading', { name: 'Supports du contrat' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Qualité des données' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Montants cumulés enregistrés' })).toBeNull();
  },
);

it('keeps lot and day-order dialogs available alongside observed insurance supports', async () => {
  api.readAccount.mockImplementation(() => ok({ ...account, kind: 'INSURANCE_CONTRACT' }));
  api.readLifeInsuranceContract.mockImplementation(() =>
    ok({
      contract: insuranceSummaryFixture().contract,
      feeTerms: [],
      euroFundRates: [],
      euroFunds: [],
    }),
  );
  const instrument = {
    id: 'instrument-1',
    name: 'ETF registre',
    isin: null,
    ticker: null,
    status: 'ACTIVE',
    quantityPrecision: 6,
  };
  api.listPortfolioPositions.mockImplementation(() =>
    ok({
      asOf: '2026-03-10',
      items: [
        {
          instrument,
          quantity: '3',
          fifoCostBasis: null,
          averageCostBasis: null,
          averageUnitCost: null,
          costReason: 'UNKNOWN_LOT_COST',
          firstAcquiredOn: '2026-03-01',
          lastOperationOn: '2026-03-01',
          openLotCount: 1,
          flags: [{ code: 'ORDER_DEPENDENT_DAY', dates: ['2026-03-01'] }],
        },
      ],
    }),
  );
  api.readPortfolioPositionLots.mockImplementation(() =>
    ok({ asOf: '2026-03-10', instrument, openLots: [], movements: [], fifoCostBasis: null }),
  );
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=positions&asOf=2026-03-10`);
  renderPage();
  await waitFor(() => expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull());
  expect(await screen.findByRole('heading', { name: 'Supports du contrat' })).toBeTruthy();
  fireEvent.click(await screen.findByRole('button', { name: 'Voir les lots' }));
  const lots = await screen.findByRole('dialog', { name: 'Lots : ETF registre' });
  await waitFor(() =>
    expect(api.readPortfolioPositionLots).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { accountId, instrumentId: instrument.id },
        query: { asOf: '2026-03-10' },
      }),
    ),
  );
  fireEvent.keyDown(lots, { key: 'Escape' });
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  fireEvent.click(screen.getByRole('button', { name: 'Ordre à vérifier' }));
  expect(
    await screen.findByRole('dialog', { name: 'Réordonner la journée du 01/03/2026' }),
  ).toBeTruthy();
});
