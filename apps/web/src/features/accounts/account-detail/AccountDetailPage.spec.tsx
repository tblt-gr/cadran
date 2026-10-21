import type { Account, AccountRules, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountDetailPage } from './AccountDetailPage';
import { AppShell } from '@/components/layout/app-shell/AppShell';

const api = vi.hoisted(() => ({
  getTransaction: vi.fn(),
  updateTransaction: vi.fn(),
  voidTransaction: vi.fn(),
  duplicateTransaction: vi.fn(),
  getTransfer: vi.fn(),
  updateTransfer: vi.fn(),
  voidTransfer: vi.fn(),
  reconcileTransaction: vi.fn(),
  getTransactionRefundable: vi.fn(),
  createTransactionRefund: vi.fn(),
  readPeriodStatus: vi.fn(),
  listCategories: vi.fn(),
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  recordPortfolioOperation: vi.fn(),
  listAccounts: vi.fn(),
  listAccountRuleOverrides: vi.fn(),
  listTransactions: vi.fn(),
  readAccount: vi.fn(),
  readAccountValuationHistory: vi.fn(),
  listAccountBalances: vi.fn(),
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
  basis: null,
  derivation: null,
  reason: null,
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
    api.readAccountValuationHistory.mockResolvedValue({
      data: { accountId: account.id, from: TODAY, to: TODAY, hasHistory: false, points: [] },
      response: new Response('{}', { status: 200 }),
    });
    vi.clearAllMocks();
    api.readPeriodStatus.mockImplementation(() => success({ closed: false }));
    api.getTransaction.mockImplementation(() => success(movement));
    api.listCategories.mockImplementation(() => success({ items: [], total: 0 }));
    api.readAccount.mockImplementation(() => success(account));
    api.listTransactions.mockImplementation(() =>
      success({ items: [movement], nextCursor: null, hasMore: false, pageSize: 50 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, otherAccount], page: 1, pageSize: 100, total: 2 }),
    );
    api.listAccountBalances.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
    api.listAccountRuleOverrides.mockImplementation(() => success({ overrides: [] }));
    api.readAccountRules.mockImplementation(() => success(noProductRules));
  });

  afterEach(() => {
    cleanup();
  });

  it('inspects authoritative details and restores keyboard focus to the row', async () => {
    api.getTransaction.mockImplementation(() => success({ ...movement, note: 'Note vérifiée' }));
    renderPage();
    await loaded();
    const inspect = await screen.findByRole('button', {
      name: 'Consulter la transaction « Supermarché »',
    });
    inspect.focus();
    fireEvent.click(inspect);
    const dialog = await screen.findByRole('dialog', { name: 'Détails de la transaction' });
    expect(await within(dialog).findByText('Note vérifiée')).toBeTruthy();
    expect(api.getTransaction).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: movement.id } }),
    );
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(document.activeElement).toBe(inspect));
    expect(
      screen.queryByText('La liste n’a pas d’autre mouvement à charger pour ces filtres.'),
    ).toBeNull();
  });

  it('edits through the shared mutation, retains scope and removes a row moved to another period', async () => {
    const moved = { ...movement, bookedOn: '2026-08-10', version: 2 };
    api.updateTransaction.mockImplementation(() => {
      api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
      return success(moved);
    });
    const { queryClient } = renderPage(account.id, '?month=2026-09');
    await loaded();
    await screen.findByText('Supermarché');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    fireEvent.click(
      screen.getByRole('button', { name: 'Modifier la transaction « Supermarché »' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByLabelText('Date comptable'), {
      target: { value: '2026-08-10' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    await waitFor(() => expect(screen.queryByText('Supermarché')).toBeNull());
    await waitFor(() =>
      expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'Mouvements' })),
    );
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({
          accountId: [account.id],
          from: '2026-09-01',
          to: '2026-09-30',
          cursor: undefined,
        }),
      }),
    );
    expect(
      queryClient
        .getQueryCache()
        .getAll()
        .some((q) => q.queryKey[0] === 'transactions'),
    ).toBe(true);
  });

  it('voids the complete transfer instead of a standalone movement', async () => {
    const leg = { ...movement, nature: 'TRANSFER' as const, transferId: 'transfer-id' };
    api.listTransactions.mockImplementation(() => success({ items: [leg], nextCursor: null }));
    const transfer = {
      id: 'transfer-id',
      source: leg,
      target: { ...leg, id: 'target', accountId: otherAccount.id },
      fee: null,
      version: 7,
      voidedAt: null,
    };
    api.getTransfer.mockImplementation(() => success(transfer));
    api.voidTransfer.mockImplementation(() =>
      success({ ...transfer, voidedAt: '2026-09-20T00:00:00Z' }),
    );
    renderPage();
    await loaded();
    await screen.findByText('Supermarché');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Annuler la transaction « Supermarché »' }));
    const dialog = await screen.findByRole('dialog', { name: 'Annuler le virement' });
    await waitFor(() =>
      expect(
        within(dialog)
          .getByRole('button', { name: 'Confirmer l’annulation du virement' })
          .closest('fieldset')?.disabled,
      ).toBe(false),
    );
    fireEvent.click(
      within(dialog).getByRole('button', { name: 'Confirmer l’annulation du virement' }),
    );
    await waitFor(() =>
      expect(api.voidTransfer).toHaveBeenCalledWith(
        expect.objectContaining({ path: { id: 'transfer-id' }, body: { version: 7 } }),
      ),
    );
    expect(api.voidTransaction).not.toHaveBeenCalled();
    expect(
      await screen.findByText('Le virement a été annulé. Il reste dans l’historique.'),
    ).toBeTruthy();
  });

  it('reconciles a reviewed pending row using fresh same-account candidates', async () => {
    const reviewed = {
      ...movement,
      state: 'PENDING' as const,
      reviewReason: 'AMBIGUOUS_MATCH' as const,
      reconciliationCandidateIds: ['candidate', 'foreign'],
    };
    const candidate = {
      ...movement,
      id: 'candidate',
      rawLabel: 'Autorisation carte',
      state: 'PENDING' as const,
    };
    api.listTransactions.mockImplementation(() => success({ items: [reviewed], nextCursor: null }));
    api.getTransaction.mockImplementation(({ path }: { path: { id: string } }) =>
      success(
        path.id === reviewed.id
          ? reviewed
          : path.id === 'candidate'
            ? candidate
            : { ...candidate, id: 'foreign', accountId: otherAccount.id, rawLabel: 'Autre compte' },
      ),
    );
    api.reconcileTransaction.mockImplementation(() =>
      success({ ...reviewed, state: 'BOOKED', version: 2 }),
    );
    renderPage();
    await loaded();
    await screen.findByText('Supermarché');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    fireEvent.click(
      screen.getByRole('button', { name: 'Rapprocher la transaction « Supermarché »' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Rapprocher la transaction' });
    await within(dialog).findByRole('option', { name: /Autorisation carte/ });
    expect(within(dialog).queryByRole('option', { name: /Autre compte/ })).toBeNull();
    fireEvent.change(within(dialog).getByLabelText('Autorisation en attente correspondante'), {
      target: { value: 'candidate' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer le rapprochement' }));
    await waitFor(() =>
      expect(api.reconcileTransaction).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: reviewed.id },
          body: { version: 1, matchedTransactionId: 'candidate' },
        }),
      ),
    );
    expect(await screen.findByText('La transaction a été rapprochée.')).toBeTruthy();
  });

  async function openRowAction(label: string, action: string) {
    await screen.findByText(label);
    fireEvent.click(screen.getByRole('button', { name: `Actions de la transaction « ${label} »` }));
    fireEvent.click(screen.getByRole('button', { name: `${action} la transaction « ${label} »` }));
  }

  it('removes a row the server moved to another account and keeps the account boundary', async () => {
    api.updateTransaction.mockImplementation(() => {
      api.listTransactions.mockImplementation(() =>
        success({ items: [], nextCursor: null, hasMore: false, pageSize: 50 }),
      );
      return success({ ...movement, accountId: otherAccount.id, version: 2 });
    });
    renderPage();
    await loaded();
    await openRowAction('Supermarché', 'Modifier');
    const dialog = await screen.findByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Déplacée' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    await waitFor(() => expect(screen.queryByText('Supermarché')).toBeNull());
    expect(screen.queryByText('Déplacée')).toBeNull();
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({ accountId: [account.id], cursor: undefined }),
      }),
    );
    expect(await screen.findByText('La transaction a été enregistrée.')).toBeTruthy();
  });

  it('voids a plain movement with its version and confirms with a toast', async () => {
    api.voidTransaction.mockImplementation(() => {
      api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
      return success({ ...movement, state: 'VOIDED', voidedAt: '2026-09-20T00:00:00Z' });
    });
    renderPage();
    await loaded();
    await openRowAction('Supermarché', 'Annuler');
    const dialog = await screen.findByRole('dialog', { name: 'Annuler la transaction' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Annuler la transaction' }));
    await waitFor(() =>
      expect(api.voidTransaction).toHaveBeenCalledWith(
        expect.objectContaining({ path: { id: movement.id }, body: { version: 1 } }),
      ),
    );
    expect(api.voidTransfer).not.toHaveBeenCalled();
    expect(
      await screen.findByText('La transaction a été annulée. Elle reste dans l’historique.'),
    ).toBeTruthy();
    await waitFor(() => expect(screen.queryByText('Supermarché')).toBeNull());
  });

  it('creates a refund from the account page and reloads the movements', async () => {
    api.getTransactionRefundable.mockImplementation(() =>
      success({
        originalId: movement.id,
        originalAmount: movement.amount,
        refunded: { value: '0', assetCode: 'EUR' },
        refundable: { value: '42.50', assetCode: 'EUR' },
        proposedSplits: [],
      }),
    );
    api.createTransactionRefund.mockImplementation(() =>
      success({ ...movement, id: 'refund', nature: 'REFUND' }),
    );
    renderPage();
    await loaded();
    await openRowAction('Supermarché', 'Créer un remboursement');
    await screen.findByLabelText('Montant');
    fireEvent.change(screen.getByLabelText('Date comptable'), { target: { value: '2026-09-12' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Remboursement' } });
    const callsBefore = api.listTransactions.mock.calls.length;
    fireEvent.click(screen.getByRole('button', { name: 'Créer le remboursement' }));
    await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledOnce());
    expect(api.createTransactionRefund.mock.calls[0]![0]).toMatchObject({
      path: { id: movement.id },
    });
    await waitFor(() =>
      expect(api.listTransactions.mock.calls.length).toBeGreaterThan(callsBefore),
    );
  });

  it('duplicates from the account page once and reloads the movements', async () => {
    api.duplicateTransaction.mockImplementation(() =>
      success({ ...movement, id: 'copy', bookedOn: TODAY }, 201),
    );
    renderPage();
    await loaded();
    await screen.findByText('Supermarché');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    const callsBefore = api.listTransactions.mock.calls.length;
    fireEvent.click(
      screen.getByRole('button', { name: 'Dupliquer la transaction « Supermarché »' }),
    );
    await waitFor(() => expect(api.duplicateTransaction).toHaveBeenCalledOnce());
    expect(api.duplicateTransaction).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: movement.id } }),
    );
    expect(
      await screen.findByText('La transaction a été dupliquée à la date du jour.'),
    ).toBeTruthy();
    await waitFor(() =>
      expect(api.listTransactions.mock.calls.length).toBeGreaterThan(callsBefore),
    );
  });

  it('shows the actual error when inspecting a transaction of another workspace (404)', async () => {
    const body = {
      type: '/problems/not-found',
      title: 'Introuvable',
      status: 404,
      detail: 'Cette transaction est introuvable.',
    };
    api.getTransaction.mockImplementation(() =>
      Promise.resolve({
        error: body,
        response: new Response(JSON.stringify(body), { status: 404 }),
      }),
    );
    renderPage();
    await loaded();
    fireEvent.click(
      await screen.findByRole('button', { name: 'Consulter la transaction « Supermarché »' }),
    );
    const dialog = await screen.findByRole('dialog', { name: 'Détails de la transaction' });
    expect(await within(dialog).findByText('Cette transaction est introuvable.')).toBeTruthy();
    expect(within(dialog).queryByText('Supermarché')).toBeNull();
  });

  it('resets the cursor to the first page after a write made from page 2', async () => {
    const older: Transaction = {
      ...movement,
      id: '00000000-0000-7000-8000-000000000e02',
      rawLabel: 'Plus ancien',
    };
    api.listTransactions.mockImplementation(({ query }: { query: { cursor?: string } }) =>
      query.cursor === 'page-2'
        ? success({ items: [older], nextCursor: null, hasMore: false, pageSize: 50 })
        : success({ items: [movement], nextCursor: 'page-2', hasMore: true, pageSize: 50 }),
    );
    api.updateTransaction.mockImplementation(() => success({ ...older, version: 2 }));
    renderPage();
    await loaded();
    fireEvent.click(await screen.findByRole('button', { name: 'Mouvements plus anciens' }));
    await openRowAction('Plus ancien', 'Modifier');
    const dialog = await screen.findByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Modifié' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    await waitFor(() => expect(screen.queryByText('Plus ancien')).toBeNull());
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ cursor: undefined }) }),
    );
    expect(await screen.findByRole('button', { name: 'Mouvements plus anciens' })).toBeTruthy();
  });

  it('confirms a saved transfer with a toast', async () => {
    const leg = { ...movement, nature: 'TRANSFER' as const, transferId: 'transfer-id' };
    api.listTransactions.mockImplementation(() => success({ items: [leg], nextCursor: null }));
    const transfer = {
      id: 'transfer-id',
      source: leg,
      target: { ...leg, id: 'target', accountId: otherAccount.id },
      fee: null,
      version: 7,
      voidedAt: null,
    };
    api.getTransfer.mockImplementation(() => success(transfer));
    api.updateTransfer.mockImplementation(() => success({ ...transfer, version: 8 }));
    renderPage();
    await loaded();
    await openRowAction('Supermarché', 'Modifier');
    const dialog = await screen.findByRole('dialog');
    await waitFor(() =>
      expect(
        within(dialog).getByRole('button', { name: 'Enregistrer' }).closest('fieldset')?.disabled,
      ).toBe(false),
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.updateTransfer).toHaveBeenCalledWith(
        expect.objectContaining({ path: { id: 'transfer-id' } }),
      ),
    );
    expect(await screen.findByText('Le virement a été enregistré.')).toBeTruthy();
  });

  it('keeps the entered draft and actual closure error after a refused account-entry edit', async () => {
    api.updateTransaction.mockImplementation(() => {
      const error = {
        type: '/problems/period-closed',
        title: 'Clôture',
        status: 409,
        detail: 'Septembre a été clôturé ailleurs.',
      };
      return Promise.resolve({
        error,
        response: new Response(JSON.stringify(error), { status: 409 }),
      });
    });
    renderPage();
    await loaded();
    await screen.findByText('Supermarché');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    fireEvent.click(
      screen.getByRole('button', { name: 'Modifier la transaction « Supermarché »' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: 'Libellé conservé' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    expect(await within(dialog).findByText('Septembre a été clôturé ailleurs.')).toBeTruthy();
    expect((within(dialog).getByLabelText('Libellé') as HTMLInputElement).value).toBe(
      'Libellé conservé',
    );
  });

  it('explains closed-period restrictions while inspection remains available', async () => {
    api.readPeriodStatus.mockImplementation(() => success({ closed: true }));
    renderPage();
    await loaded();
    expect(await screen.findByText('Cette période est clôturée.')).toBeTruthy();
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « Supermarché »' }),
    );
    expect(
      (
        screen.getByRole('button', {
          name: 'Modifier la transaction « Supermarché »',
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
    expect(
      (
        screen.getByRole('button', {
          name: 'Annuler la transaction « Supermarché »',
        }) as HTMLButtonElement
      ).disabled,
    ).toBe(true);
    expect(
      screen
        .getAllByRole('button', { name: 'Consulter la transaction « Supermarché »' })
        .every((button) => !(button as HTMLButtonElement).disabled),
    ).toBe(true);
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
      success({ items: [account, destination], page: 1, pageSize: 100, total: 2 }),
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
        success({ items: [account, destination], page: 1, pageSize: 100, total: 2 }),
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

    expect(await screen.findByText('Ouverture')).toBeTruthy();
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
  it('keeps loaded movements after a recoverable pagination failure and retries with the same account and month', async () => {
    let fail = true;
    api.listTransactions.mockImplementation(({ query }: { query: { cursor?: string } }) => {
      if (query.cursor !== 'page-2') return success({ items: [movement], nextCursor: 'page-2' });
      if (fail) return failure(503);
      return success({
        items: [{ ...movement, id: 'older', rawLabel: 'Plus ancien' }],
        nextCursor: null,
      });
    });
    renderPage(account.id, '?month=2026-09');
    await loaded();
    fireEvent.click(await screen.findByRole('button', { name: 'Mouvements plus anciens' }));
    expect(
      await screen.findByText('La liste des transactions n’a pas pu être chargée.'),
    ).toBeTruthy();
    expect(screen.getByText('Supermarché')).toBeTruthy();
    fail = false;
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('Plus ancien')).toBeTruthy();
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({
          accountId: [account.id],
          from: '2026-09-01',
          to: '2026-09-30',
          cursor: 'page-2',
        }),
      }),
    );
    expect(screen.queryByRole('button', { name: 'Mouvements plus anciens' })).toBeNull();
    expect(
      screen.queryByText('La liste n’a pas d’autre mouvement à charger pour ces filtres.'),
    ).toBeNull();
  });

  it('mounts savings history independently of the account identity read', async () => {
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Historique du solde' })).toBeTruthy();
    expect(api.readAccountValuationHistory).toHaveBeenCalled();
  });
});
