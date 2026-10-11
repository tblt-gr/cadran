import type { Account, AccountRules, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountDetailPage } from './AccountDetailPage';
import { AppShell } from '@/components/layout/app-shell/AppShell';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  recordPortfolioOperation: vi.fn(),
  listAccounts: vi.fn(),
  listAccountRuleOverrides: vi.fn(),
  listTransactions: vi.fn(),
  readAccount: vi.fn(),
  readAccountRules: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const TODAY = '2026-09-24';

vi.mock('@/lib/businessDay', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/businessDay')>()),
  todayInBrowser: () => TODAY,
}));

const missingValuation = {
  accountId: '00000000-0000-7000-8000-0000000000d1',
  requestedOn: TODAY,
  asOf: null,
  amount: null,
  display: null,
  belowDisplayStep: false,
  source: null,
  ageDays: null,
  quality: 'MISSING' as const,
  reconciliationStatus: null,
  snapshotId: null,
  version: null,
};

const account: Account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Livret A Banque X',
  assetCode: 'EUR',
  kind: 'SAVINGS',
  productCode: null,
  productModelId: null,
  institution: 'Banque X',
  maskedIdentifier: '4821',
  valuationMode: 'TRANSACTIONS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: true,
  openedOn: '2026-01-10',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  tagGroupIds: [],
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  valuation: missingValuation,
};

const otherAccount: Account = {
  ...account,
  id: '00000000-0000-7000-8000-0000000000d2',
  label: 'Compte courant',
};

const movement: Transaction = {
  id: '00000000-0000-7000-8000-000000000e01',
  accountId: account.id,
  amount: { value: '-42.50', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  sourceRef: null,
  bookedOn: '2026-09-10',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'Supermarché',
  counterparty: null,
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 1,
  createdAt: '2026-09-10T10:00:00Z',
  updatedAt: '2026-09-10T10:00:00Z',
  voidedAt: null,
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  reviewReason: null,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
};

const noProductRules: AccountRules = {
  accountId: account.id,
  assetCode: 'EUR',
  productCode: null,
  productModelId: null,
  origin: 'NO_PRODUCT',
  asOf: TODAY,
  ceilings: [],
  rates: [],
  terms: [],
  unavailableRuleKinds: [],
  yieldReading: {
    contractual: null,
    assumption: null,
    assumptionReason: 'NOT_RECORDED',
    observed: null,
    observedReason: 'NO_RETURN_SERIES',
  },
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function failure(status: number) {
  return Promise.resolve({ data: undefined, response: new Response('{}', { status }) });
}

function problem(status: number, type: string) {
  const body = { type, title: 'Conflit', status, detail: 'Conflit' };

  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function renderPage(accountId = account.id, search = '', shell = false) {
  window.history.pushState({}, '', `/accounts/${accountId}${search}`);
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        {shell ? (
          <AppShell path={`/accounts/${accountId}`} setPath={vi.fn()}>
            <AccountDetailPage accountId={accountId} />
          </AppShell>
        ) : (
          <AccountDetailPage accountId={accountId} />
        )}
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('account-detail-skeleton')).toBeNull());
}

describe('AccountDetailPage', () => {
  it('publishes its account name without another query and keeps portfolio actions', async () => {
    api.readAccount.mockImplementation(() =>
      success({ ...account, kind: 'PORTFOLIO', valuationMode: 'PORTFOLIO' }),
    );
    renderPage(account.id, '?month=2026-03', true);
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    await waitFor(() =>
      expect(within(nav).getByText(account.label).getAttribute('aria-current')).toBe('page'),
    );
    expect(api.readAccount).toHaveBeenCalledTimes(1);
    expect(within(nav).getByRole('link', { name: 'Comptes' }).getAttribute('href')).toBe(
      '/accounts',
    );
    expect(screen.queryByRole('link', { name: 'Retour aux comptes' })).toBeNull();
    fireEvent.click(await screen.findByRole('button', { name: 'Autres actions' }));
    expect(screen.getByRole('button', { name: 'Ouvrir le portefeuille' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Valoriser le portefeuille' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Valoriser le portefeuille' }));
    expect(window.location.pathname).toBe(`/portfolios/${account.id}`);
    expect(window.location.search).toBe('?tab=valuations');
    window.history.replaceState({}, '', `/accounts/${account.id}?month=2026-03`);
    expect(window.location.search).toBe('?month=2026-03');
  });
  beforeEach(() => {
    vi.clearAllMocks();
    api.readAccount.mockImplementation(() => success(account));
    api.listTransactions.mockImplementation(() =>
      success({ items: [movement], nextCursor: null, hasMore: false, pageSize: 50 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, otherAccount], page: 1, perPage: 100, total: 2 }),
    );
    api.listAccountRuleOverrides.mockImplementation(() => success({ overrides: [] }));
    api.readAccountRules.mockImplementation(() => success(noProductRules));
  });

  afterEach(() => {
    cleanup();
  });

  it('keeps the shared transfer modal open until the opted-in deposit succeeds', async () => {
    let finishDeposit!: (value: unknown) => void;
    api.recordPortfolioOperation.mockReturnValue(
      new Promise((resolve) => {
        finishDeposit = resolve;
      }),
    );
    const destination = {
      ...otherAccount,
      kind: 'PORTFOLIO' as const,
      valuationMode: 'PORTFOLIO' as const,
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, destination], page: 1, perPage: 100, total: 2 }),
    );
    api.createTransfer.mockImplementation(() =>
      success({
        target: {
          ...movement,
          id: 'incoming',
          accountId: destination.id,
          amount: { value: '1500.00', assetCode: 'EUR' },
          bookedOn: '2026-03-10',
        },
      }),
    );
    renderPage();
    await loaded();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouveau virement' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nouveau virement' });
    await within(within(dialog).getByLabelText('Compte de destination')).findByRole('option', {
      name: destination.label,
    });
    fireEvent.change(within(dialog).getByLabelText('Compte de destination'), {
      target: { value: destination.id },
    });
    fireEvent.change(within(dialog).getByLabelText('Montant (EUR)'), {
      target: { value: '1500.00' },
    });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: 'Investissement mensuel' },
    });
    fireEvent.click(within(dialog).getByLabelText('Créer le versement sur le compte de réception'));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer le virement' }));
    await waitFor(() => expect(api.recordPortfolioOperation).toHaveBeenCalledOnce());
    expect(screen.getByRole('dialog', { name: 'Nouveau virement' })).toBeTruthy();
    expect(api.createTransfer).toHaveBeenCalledOnce();
    finishDeposit({ response: { ok: true }, data: {} });
    await waitFor(() =>
      expect(screen.queryByRole('dialog', { name: 'Nouveau virement' })).toBeNull(),
    );
  });

  it.each(['server', 'network'])(
    'keeps the committed transfer through a %s account refetch failure',
    async (failureKind) => {
      const destination = {
        ...otherAccount,
        kind: 'PORTFOLIO' as const,
        valuationMode: 'PORTFOLIO' as const,
      };
      api.listAccounts.mockImplementation(() =>
        success({ items: [account, destination], page: 1, perPage: 100, total: 2 }),
      );
      api.readAccount
        .mockImplementationOnce(() => success(account))
        .mockImplementation(() =>
          failureKind === 'server'
            ? failure(500)
            : Promise.reject(new TypeError('Failed to fetch')),
        );
      api.createTransfer.mockImplementation(() =>
        success(
          {
            target: {
              ...movement,
              id: 'incoming',
              accountId: destination.id,
              amount: { value: '1500.00', assetCode: 'EUR' },
              bookedOn: '2026-03-10',
            },
          },
          201,
        ),
      );
      api.recordPortfolioOperation
        .mockResolvedValueOnce({
          response: { ok: false, status: 409 },
          error: { code: 'portfolio_operation_conflict' },
        })
        .mockResolvedValueOnce({ response: { ok: true }, data: {} });
      renderPage();
      await loaded();
      fireEvent.click(await screen.findByRole('button', { name: 'Nouveau virement' }));
      const dialog = await screen.findByRole('dialog', { name: 'Nouveau virement' });
      await within(within(dialog).getByLabelText('Compte de destination')).findByRole('option', {
        name: destination.label,
      });
      fireEvent.change(within(dialog).getByLabelText('Compte de destination'), {
        target: { value: destination.id },
      });
      fireEvent.change(within(dialog).getByLabelText('Montant (EUR)'), {
        target: { value: '1500.00' },
      });
      fireEvent.change(within(dialog).getByLabelText('Libellé'), {
        target: { value: 'Investissement mensuel' },
      });
      fireEvent.click(
        within(dialog).getByLabelText('Créer le versement sur le compte de réception'),
      );
      fireEvent.click(within(dialog).getByRole('button', { name: 'Créer le virement' }));
      await within(dialog).findByText(
        'Actualisation du compte impossible. Les dernières données disponibles restent affichées.',
      );
      await within(dialog).findByRole('button', { name: 'Réessayer le versement' });
      expect((within(dialog).getByLabelText('Montant (EUR)') as HTMLInputElement).disabled).toBe(
        true,
      );
      fireEvent.click(within(dialog).getByRole('button', { name: 'Réessayer le versement' }));
      await waitFor(() =>
        expect(screen.queryByRole('dialog', { name: 'Nouveau virement' })).toBeNull(),
      );
      expect(api.createTransfer).toHaveBeenCalledOnce();
      expect(api.recordPortfolioOperation).toHaveBeenCalledTimes(2);
      expect(
        await screen.findByText(
          'Actualisation du compte impossible. Les dernières données disponibles restent affichées.',
        ),
      ).toBeTruthy();
    },
  );

  it.each([401, 403, 404])('hides cached account data after refetch status %s', async (status) => {
    const { queryClient } = renderPage();
    await loaded();
    expect(screen.getAllByRole('heading', { name: account.label }).length).toBeGreaterThan(0);
    api.readAccount.mockImplementation(() => failure(status));
    void queryClient.invalidateQueries({ queryKey: ['account', account.id] });
    await waitFor(() =>
      expect(screen.queryAllByRole('heading', { name: account.label })).toHaveLength(0),
    );
    expect(screen.getByRole('alert')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Nouveau virement' })).toBeNull();
  });

  it('replaces its one named cold-load skeleton and keeps the account during a refetch', async () => {
    let resolveAccount: ((value: unknown) => void) | undefined;
    api.readAccount.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveAccount = resolve;
        }),
    );
    const { queryClient } = renderPage();

    expect(screen.getByTestId('account-detail-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    resolveAccount?.({ data: account, response: new Response('{}', { status: 200 }) });
    expect(await screen.findByRole('heading', { level: 3, name: account.label })).toBeTruthy();
    expect(screen.queryByTestId('account-detail-skeleton')).toBeNull();

    api.readAccount.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['account', account.id] });
    await waitFor(() => expect(api.readAccount).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('heading', { level: 3, name: account.label })).toBeTruthy();
    expect(screen.queryByTestId('account-detail-skeleton')).toBeNull();
  });

  it('opens the account named in Budget or Accounts and shows its identity and movements', async () => {
    renderPage();

    expect(
      await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' }),
    ).toBeTruthy();
    expect(screen.getByText('Supermarché')).toBeTruthy();
    expect(api.listTransactions).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ accountId: [account.id] }) }),
    );
  });

  it('links a portfolio-mode account to its portfolio page and no other account', async () => {
    renderPage();
    await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' });
    expect(screen.queryByRole('button', { name: 'Autres actions' })).toBeNull();
    cleanup();
    api.readAccount.mockImplementation(() =>
      success({ ...account, kind: 'PORTFOLIO', valuationMode: 'PORTFOLIO' }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Autres actions' }));
    fireEvent.click(screen.getByRole('button', { name: 'Ouvrir le portefeuille' }));
    expect(window.location.pathname).toBe(`/portfolios/${account.id}`);
    window.history.replaceState({}, '', '/');
  });

  it('replaces Rapprocher by a valuation link for a portfolio-mode account only', async () => {
    renderPage();
    await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' });
    expect(screen.getByRole('button', { name: 'Rapprocher' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Valoriser le portefeuille' })).toBeNull();
    cleanup();
    api.readAccount.mockImplementation(() =>
      success({ ...account, kind: 'PORTFOLIO', valuationMode: 'PORTFOLIO' }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Autres actions' }));
    fireEvent.click(screen.getByRole('button', { name: 'Valoriser le portefeuille' }));
    expect(window.location.search).toBe('?tab=valuations');
    window.history.replaceState({}, '', '/');
    expect(screen.queryByRole('button', { name: 'Rapprocher' })).toBeNull();
  });

  it('discloses no account data for an unknown or foreign-workspace identifier', async () => {
    api.readAccount.mockImplementation(() => failure(404));
    renderPage('00000000-0000-7000-8000-000000000fff');

    expect((await screen.findByRole('alert')).textContent).toMatch('Compte introuvable');
    expect(screen.queryByText('Livret A Banque X')).toBeNull();
  });

  it('shows the same not-found state for a workspace-access refusal as for a missing account', async () => {
    api.readAccount.mockImplementation(() => failure(403));
    renderPage();

    expect((await screen.findByRole('alert')).textContent).toMatch('Compte introuvable');
  });

  it('keeps an archived account linkable and readable, with creation explained as unavailable', async () => {
    api.readAccount.mockImplementation(() =>
      success({
        ...account,
        status: 'ARCHIVED',
        editable: false,
        archivedAt: '2026-09-20T10:00:00Z',
      }),
    );
    renderPage();

    expect(
      await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' }),
    ).toBeTruthy();
    expect(screen.getByText(/archivé et n’accepte plus de nouveau mouvement/)).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Nouvelle transaction' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });

  it('shows a missing-valuation state, not zero, when the account has no dated source', async () => {
    renderPage();

    await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' });
    expect(screen.getByText('Non calculable')).toBeTruthy();
  });

  it('never widens the movement query beyond this account, even across pages', async () => {
    renderPage();

    await screen.findByText('Supermarché');
    for (const call of api.listTransactions.mock.calls) {
      expect(call[0].query.accountId).toEqual([account.id]);
    }
  });

  it('opens the shared transaction modal with this account prefilled', async () => {
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));

    const dialog = await screen.findByRole('dialog');
    const select = within(dialog).getByLabelText('Compte') as HTMLSelectElement;
    expect(within(select).getAllByRole('option')).toHaveLength(1);
    expect(select.value).toBe(account.id);
  });

  it('opens the shared transfer modal with this account selected as source', async () => {
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Nouveau virement' }));

    const dialog = await screen.findByRole('dialog');
    await waitFor(() => {
      const select = within(dialog).getByLabelText('Compte source') as HTMLSelectElement;
      expect(select.value).toBe(account.id);
    });
  });

  it('refuses a write against a closed period with the shared explanation', async () => {
    api.createTransaction.mockImplementation(() => problem(409, '/problems/period-closed'));
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));
    const dialog = await screen.findByRole('dialog');

    fireEvent.change(within(dialog).getByLabelText('Montant'), { target: { value: '-12.50' } });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Test' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    expect((await within(dialog).findByRole('alert')).textContent).toMatch('clôturée');
  });

  it('shows the applicable rules from the shared rules panel', async () => {
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Voir les règles' }));

    expect(await screen.findByText(/Règles résolues au/)).toBeTruthy();
    expect(api.readAccountRules).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: account.id } }),
    );
  });

  it('closes an open modal on Escape', async () => {
    renderPage();
    await loaded();

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.keyDown(dialog, { key: 'Escape' });

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
  });

  it('preselects the period a Budget link asked for, and shows a control to clear it', async () => {
    renderPage(account.id, '?month=2026-08');

    await screen.findByText('Supermarché');
    expect(api.listTransactions).toHaveBeenCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({ from: '2026-08-01', to: '2026-08-31' }),
      }),
    );
    expect(screen.getByText(/Période affichée/)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Voir tout l’historique' }));

    await waitFor(() => {
      expect(api.listTransactions).toHaveBeenCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ from: undefined, to: undefined }),
        }),
      );
    });
    expect(screen.queryByText(/Période affichée/)).toBeNull();
  });

  it('shows no active-period control for a direct account entry with no month', async () => {
    renderPage();

    await screen.findByText('Supermarché');
    expect(screen.queryByText(/Période affichée/)).toBeNull();
  });

  it('shows a stale-valuation state with its age and source, not a fresh figure', async () => {
    api.readAccount.mockImplementation(() =>
      success({
        ...account,
        valuation: {
          ...missingValuation,
          asOf: '2026-09-10',
          amount: { value: '231.10', assetCode: 'EUR' },
          display: { value: '231.10', assetCode: 'EUR' },
          source: 'MANUAL',
          ageDays: 14,
          quality: 'STALE',
          reconciliationStatus: 'UNRECONCILED',
          snapshotId: '00000000-0000-7000-8000-0000000000b1',
          version: 1,
        },
      }),
    );
    renderPage();

    expect(await screen.findByText(/Ancienne/)).toBeTruthy();
    expect(screen.getByText(/14 jours/)).toBeTruthy();
  });

  it('hides the empty-movements create action for an account creation is unavailable on', async () => {
    api.readAccount.mockImplementation(() =>
      success({
        ...account,
        status: 'ARCHIVED',
        editable: false,
        archivedAt: '2026-09-20T10:00:00Z',
      }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [], nextCursor: null, hasMore: false, pageSize: 50 }),
    );
    renderPage();

    await screen.findByRole('heading', { level: 3, name: 'Livret A Banque X' });
    expect(screen.getByText('Aucune transaction')).toBeTruthy();
    expect(
      screen.queryByRole('button', { name: 'Enregistrer la première transaction' }),
    ).toBeNull();
  });

  it('loads an older page of movements without widening the account boundary', async () => {
    const older: Transaction = { ...movement, id: '00000000-0000-7000-8000-000000000e02' };
    api.listTransactions.mockImplementation(({ query }: { query: { cursor?: string } }) =>
      query.cursor === 'page-2'
        ? success({ items: [older], nextCursor: null, hasMore: false, pageSize: 50 })
        : success({ items: [movement], nextCursor: 'page-2', hasMore: true, pageSize: 50 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Mouvements plus anciens' }));

    await waitFor(() => {
      const secondCall = api.listTransactions.mock.calls.find(
        (call) => call[0].query.cursor === 'page-2',
      );
      expect(secondCall).toBeDefined();
      expect(secondCall![0].query.accountId).toEqual([account.id]);
    });
  });
});
