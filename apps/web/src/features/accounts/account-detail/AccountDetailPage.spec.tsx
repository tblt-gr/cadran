import type { Account, AccountRules, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountDetailPage } from './AccountDetailPage';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
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

function renderPage(accountId = account.id, search = '') {
  window.history.pushState({}, '', `/accounts/${accountId}${search}`);
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <AccountDetailPage accountId={accountId} />
    </QueryClientProvider>,
  );
}

describe('AccountDetailPage', () => {
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

  it('opens the account named in Budget or Accounts and shows its identity and movements', async () => {
    renderPage();

    expect(
      await screen.findByRole('heading', { level: 2, name: 'Livret A Banque X' }),
    ).toBeTruthy();
    expect(screen.getByText('Supermarché')).toBeTruthy();
    expect(api.listTransactions).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ accountId: [account.id] }) }),
    );
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
      await screen.findByRole('heading', { level: 2, name: 'Livret A Banque X' }),
    ).toBeTruthy();
    expect(screen.getByText(/archivé et n’accepte plus de nouveau mouvement/)).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Nouvelle transaction' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });

  it('shows a missing-valuation state, not zero, when the account has no dated source', async () => {
    renderPage();

    await screen.findByRole('heading', { level: 2, name: 'Livret A Banque X' });
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

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));

    const dialog = await screen.findByRole('dialog');
    const select = within(dialog).getByLabelText('Compte') as HTMLSelectElement;
    expect(within(select).getAllByRole('option')).toHaveLength(1);
    expect(select.value).toBe(account.id);
  });

  it('opens the shared transfer modal with this account selected as source', async () => {
    renderPage();

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

    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));
    const dialog = await screen.findByRole('dialog');

    fireEvent.change(within(dialog).getByLabelText('Montant'), { target: { value: '-12.50' } });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Test' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    expect((await within(dialog).findByRole('alert')).textContent).toMatch('clôturée');
  });

  it('shows the applicable rules from the shared rules panel', async () => {
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Voir les règles' }));

    expect(await screen.findByText(/Règles résolues au/)).toBeTruthy();
    expect(api.readAccountRules).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: account.id } }),
    );
  });

  it('closes an open modal on Escape', async () => {
    renderPage();

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

    await screen.findByRole('heading', { level: 2, name: 'Livret A Banque X' });
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
