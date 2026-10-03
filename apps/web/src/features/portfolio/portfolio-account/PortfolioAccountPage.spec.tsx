import type { Account, PortfolioOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { operationFixture } from './operations/operations-table/operationFixtures';
import { PortfolioAccountPage } from './PortfolioAccountPage';

const api = vi.hoisted(() => ({
  correctPortfolioOperation: vi.fn(),
  getSession: vi.fn(),
  listInstruments: vi.fn(),
  listPortfolioOperations: vi.fn(),
  listPortfolioPositions: vi.fn(),
  listPortfolioTransferCandidates: vi.fn(),
  listProducts: vi.fn(),
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
  it('switches from the default positions tab before opening the global create action', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}`);
    renderPage();

    await screen.findByText('Aucune position à cette date');
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle opération' }));

    expect(window.location.search).toBe('?tab=operations');
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
      const loadingDate = screen.getByLabelText('Au') as HTMLInputElement;
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
    expect((screen.getByLabelText('Au') as HTMLInputElement).type).toBe('date');
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
