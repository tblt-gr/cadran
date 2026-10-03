import type { Account, PortfolioOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { positionsValuationFixture } from '@/features/portfolio/valuations/valuationFixtures';
import { cashFixture } from '@/features/portfolio/cash/cashFixtures';
import { operationFixture } from './operations/operations-table/operationFixtures';
import { summaryFixture, allocationFixture } from './overviewFixtures';
import { PortfolioAccountPage } from './PortfolioAccountPage';

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
  readPortfolioSummary: vi.fn(),
  readPortfolioAllocation: vi.fn(),
  readPortfolioValuation: vi.fn(),
  previewPortfolioOperation: vi.fn(),
  readAccount: vi.fn(),
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
  productCode: null,
} as Account;

function ok<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response('{}', { status }) });
}
function problem(status: number, body: object) {
  return Promise.resolve({ error: body, response: new Response('{}', { status }) });
}
function page(items: PortfolioOperation[]) {
  return ok({ items, page: 1, perPage: 50, total: items.length });
}
function renderPage(timeZone = 'Europe/Paris') {
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
  return render(
    <QueryClientProvider client={client}>
      <PortfolioAccountPage accountId={accountId} />
    </QueryClientProvider>,
  );
}
function openAction(name: string) {
  fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
  fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
}

const deposit = operationFixture({ id: 'op-1', version: 4 });

beforeEach(() => {
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=operations`);
  api.readPortfolioSummary.mockImplementation(() => ok(summaryFixture()));
  api.readPortfolioAllocation.mockImplementation(() => ok(allocationFixture()));
  api.readAccount.mockImplementation(() => ok(account));
  api.listProducts.mockImplementation(() => ok({ items: [], page: 1, perPage: 100, total: 0 }));
  api.listInstruments.mockImplementation(() => ok({ items: [], page: 1, perPage: 20, total: 0 }));
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
  it('keeps dated complete values while explicitly warning about a newer partial valuation', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?asOf=2026-10-03&tab=operations`);
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
        'Valorisation du 03/10/2026 partielle : dernières valeurs complètes du 30/09/2026 affichées.',
      ),
    ).toBeTruthy();
    expect(screen.getByText(/Gain \+121,42/)).toBeTruthy();
    expect(screen.getByText('au 30/09/2026, au prix de revient moyen')).toBeTruthy();
    expect(screen.getByText('30/09/2026', { selector: 'time' })).toBeTruthy();
    expect(screen.getByText('À vérifier (1)')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Valeur totale' }));
    expect(screen.getByText('Du 2026-09-30 au 2026-09-30')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Valorisation du 2026-09-30' })).toBeTruthy();
  });
  it('retains explicit non-calculable states when only a partial valuation exists', async () => {
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
      ok({ ...allocationFixture(), valuation: summary.valuation, reason: 'PARTIAL_VALUATION' }),
    );
    renderPage();
    expect(await screen.findByText('Non valorisé')).toBeTruthy();
    expect(
      screen.getByText('Valorisation partielle : la répartition n’est pas calculable'),
    ).toBeTruthy();
    expect(screen.queryByText(/dernières valeurs complètes/)).toBeNull();
  });
  it('shows no newer-partial warning when the server reports none for a past date', async () => {
    renderPage();
    await screen.findByText(/Gain/);
    expect(screen.queryByText(/dernières valeurs complètes/)).toBeNull();
  });

  it('propagates the selected date to every read and keeps it when switching tabs', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?asOf=2026-03-10`);
    api.readPortfolioCash.mockImplementation(() => ok(cashFixture()));
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, perPage: 24, total: 0 }),
    );
    renderPage();
    await screen.findByText('Aucune position à cette date');
    fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-02-28' } });
    await waitFor(() =>
      expect(api.readPortfolioSummary.mock.calls.at(-1)![0].query.asOf).toBe('2026-02-28'),
    );
    expect(api.readPortfolioAllocation.mock.calls.at(-1)![0].query.asOf).toBe('2026-02-28');
    expect(api.listPortfolioPositions.mock.calls.at(-1)![0].query.asOf).toBe('2026-02-28');
    fireEvent.click(screen.getByRole('link', { name: 'Opérations' }));
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
    expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.to).toBe('2026-02-28');
    fireEvent.click(screen.getByRole('link', { name: 'Espèces' }));
    await screen.findByRole('table', { name: 'Mouvements d’espèces' });
    expect(api.readPortfolioCash.mock.calls.at(-1)![0].query.to).toBe('2026-02-28');
    fireEvent.click(screen.getByRole('link', { name: 'Valorisations' }));
    await screen.findByText(/Aucune valorisation\. Enregistrez/);
    expect(api.listPortfolioValuations.mock.calls.at(-1)![0].query.to).toBe('2026-02-28');
    expect(new URLSearchParams(window.location.search).get('asOf')).toBe('2026-02-28');
  });
  it('opens an operation source from its explanation and restores keyboard focus', async () => {
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
    fireEvent.click(screen.getByRole('button', { name: `Opération du ${deposit.tradeDate}` }));
    expect(await screen.findByRole('dialog', { name: /Opération : Versement/ })).toBeTruthy();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(document.activeElement).toBe(info));
  });

  it('opens the exact valuation source from a KPI explanation with one dialog', async () => {
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, perPage: 24, total: 0 }),
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
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 2026-03-10' }));
    expect(await screen.findByRole('dialog', { name: 'Valorisation du 10/03/2026' })).toBeTruthy();
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    expect(api.readPortfolioValuation.mock.calls.at(-1)![0].path.id).toBe('valuation-1');
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(document.activeElement).toBe(info));
  });

  it('shows the Espèces layout, real chrome included, while the account loads', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=cash`);
    api.readAccount.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('link', { name: 'Espèces' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('heading', { name: 'Solde espèces' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Répartition' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Valeur totale' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Effet espèces' })).toBeTruthy();
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
    expect(
      screen.getByRole('button', { name: 'Enregistrer une valorisation' }).matches(':disabled'),
    ).toBe(true);
    expect(screen.getByRole('columnheader', { name: 'Complétude' })).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });

  it('opens the Valorisations tab after Espèces from ?tab=valuations', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=valuations`);
    api.listPortfolioValuations.mockImplementation(() =>
      ok({ items: [], page: 1, perPage: 24, total: 0 }),
    );
    renderPage();

    expect(await screen.findByText(/Aucune valorisation\. Enregistrez/)).toBeTruthy();
    const tabs = screen.getAllByRole('link').map((link) => link.textContent);
    expect(tabs.indexOf('Valorisations')).toBe(tabs.indexOf('Espèces') + 1);
  });

  it('opens the Espèces tab after Opérations from ?tab=cash', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=cash`);
    api.readPortfolioCash.mockImplementation(() => ok(cashFixture()));
    renderPage();

    expect(await screen.findByRole('table', { name: 'Mouvements d’espèces' })).toBeTruthy();
    const tabs = screen.getAllByRole('link').map((link) => link.textContent);
    expect(tabs.indexOf('Espèces')).toBe(tabs.indexOf('Opérations') + 1);
    expect(screen.getByRole('link', { name: 'Espèces' }).getAttribute('aria-current')).toBe('page');
  });

  it('switches from the default positions tab before opening the global create action', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}`);
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
    window.history.replaceState({}, '', `/portfolios/${accountId}`);
    let resolveAccount: ((value: unknown) => void) | undefined;
    api.readAccount.mockImplementation(() => new Promise((done) => (resolveAccount = done)));

    try {
      renderPage('Pacific/Auckland');
      const loadingDate = screen.getAllByLabelText('Au').at(-1) as HTMLInputElement;
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
    expect(screen.getAllByRole('columnheader', { name: 'Date' })).toHaveLength(1);
    resolve?.(await ok(account));
    expect(await screen.findByRole('heading', { name: 'PEA Démo' })).toBeTruthy();
    expect(await screen.findByRole('table', { name: 'Opérations du portefeuille' })).toBeTruthy();
    expect(screen.queryByTestId('operations-skeleton')).toBeNull();
    expect(
      (screen.getByRole('link', { name: 'Voir le compte' }) as HTMLAnchorElement).getAttribute(
        'href',
      ),
    ).toBe(`/accounts/${accountId}`);
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

    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
    expect(api.listPortfolioOperations).toHaveBeenCalledTimes(1);
    openAction('Réordonner la journée');
    expect(screen.getByRole('dialog', { name: /Réordonner la journée/ })).toBeTruthy();
  });

  it('shows an error state and retries', async () => {
    api.listPortfolioOperations.mockImplementationOnce(() => problem(500, {}));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('table', { name: 'Opérations du portefeuille' })).toBeTruthy();
  });

  it('asks for voided operations only when the toggle is on', async () => {
    renderPage();
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
    expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.includeVoided).toBe(false);
    fireEvent.click(screen.getByLabelText('Afficher les opérations annulées'));
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.includeVoided).toBe(true),
    );
  });

  it('filters the period with native date inputs', async () => {
    renderPage();
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
    fireEvent.click(screen.getByRole('button', { name: 'Types d’opération Tous les types' }));
    fireEvent.click(screen.getByRole('option', { name: 'Achat' }));
    await waitFor(() =>
      expect(api.listPortfolioOperations.mock.calls.at(-1)![0].query.type).toEqual(['BUY']),
    );
  });

  it('records a deposit with an idempotency key and refreshes the list', async () => {
    api.recordPortfolioOperation.mockImplementation(() => ok(deposit, 201));
    renderPage();
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
    await screen.findByRole('table', { name: 'Opérations du portefeuille' });
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
