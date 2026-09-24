import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyBudgetPage } from './MonthlyBudgetPage';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  getSession: vi.fn(),
  getTransaction: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  readMonthlyLedger: vi.fn(),
  readMonthlyLedgerMovements: vi.fn(),
  readMonthlyRecap: vi.fn(),
  readMonthlyRecapPreferences: vi.fn(),
  saveMonthlyRecapPreferences: vi.fn(),
  updateTransaction: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const incomeId = '00000000-0000-7000-8000-000000000001';
const expenseId = '00000000-0000-7000-8000-000000000002';
const accountId = '00000000-0000-7000-8000-000000000003';
const emptyAccountId = '00000000-0000-7000-8000-000000000010';
const movementTransactionId = '00000000-0000-7000-8000-000000000005';
const ledger = {
  month: '2026-03',
  periodStart: '2026-03-01',
  periodEnd: '2026-03-31',
  axis: null,
  state: 'COMPLETE' as const,
  quality: 'CURRENT' as const,
  timezone: 'Europe/Paris',
  firstDataMonth: '2025-06',
  pendingCount: 2,
  closed: false,
  actionsAllowed: true,
  actionReason: null,
  incomeCategories: [
    {
      id: incomeId,
      label: 'Salaire',
      icon: 'briefcase',
      color: '#486DF0',
      budgetIncluded: true,
      archived: false,
      total: { value: '0', assetCode: 'EUR', reason: null },
      movementCount: 1,
      hasMovements: true,
    },
  ],
  expenseCategories: [
    {
      id: expenseId,
      label: 'Voyages',
      icon: 'plane',
      color: '#58B8C0',
      budgetIncluded: false,
      archived: false,
      total: { value: null, assetCode: null, reason: 'NO_MOVEMENTS' as const },
      movementCount: 0,
      hasMovements: false,
    },
  ],
  accounts: [
    {
      id: accountId,
      label: 'Compte courant',
      assetCode: 'EUR',
      kind: 'CURRENT' as const,
      total: { value: '-25.00', assetCode: 'EUR', reason: null },
      movementCount: 2,
      hasMovements: true,
    },
    {
      id: emptyAccountId,
      label: 'Livret vide',
      assetCode: 'EUR',
      kind: 'SAVINGS' as const,
      total: { value: null, assetCode: null, reason: 'NO_MOVEMENTS' as const },
      movementCount: 0,
      hasMovements: false,
    },
  ],
};

const account = {
  id: accountId,
  label: 'Compte courant',
  assetCode: 'EUR',
  status: 'ACTIVE',
  openedOn: '2020-01-01',
};

const recap = {
  month: '2026-03',
  previousAsOf: '2026-02-28',
  currentAsOf: '2026-03-15',
  provisional: true,
  state: 'PENDING' as const,
  quality: 'CURRENT' as const,
  accounts: [
    {
      accountId,
      label: 'Compte courant',
      kind: 'CURRENT',
      netWorthSign: 1 as const,
      primaryGroupId: 'group-1',
      primaryGroupLabel: 'Liquidités',
      eligible: true,
      previousValue: {
        value: '100.00',
        assetCode: 'EUR',
        quality: 'CURRENT' as const,
        ageDays: 0,
        valuedOn: '2026-02-28',
      },
      currentValue: {
        value: null,
        assetCode: null,
        quality: 'MISSING' as const,
        ageDays: null,
        valuedOn: null,
      },
      share: {
        ratio: null,
        percent: null,
        percentDisplay: null,
        reason: 'MISSING_VALUATION' as const,
      },
    },
  ],
  groups: [
    {
      groupId: 'group-1',
      label: 'Liquidités',
      parentId: null,
      depth: 1,
      value: null,
      share: {
        ratio: null,
        percent: null,
        percentDisplay: null,
        reason: 'MISSING_VALUATION' as const,
      },
    },
  ],
  netWorth: {
    previous: {
      value: '100.00',
      assetCode: 'EUR',
      display: { value: '100.00', assetCode: 'EUR' },
      belowDisplayStep: false,
    },
    previousReason: null,
    current: null,
    currentReason: 'MISSING_VALUATION' as const,
    difference: null,
    differenceReason: 'MISSING_VALUATION' as const,
    changeRatio: null,
    changePercent: null,
    changePercentDisplay: null,
    changeReason: 'MISSING_VALUATION' as const,
    previousQuality: 'CURRENT' as const,
    previousStalestAgeDays: 0,
    previousMissingValuationCount: 0,
    previousStaleValuationCount: 0,
    quality: 'MISSING' as const,
    stalestAgeDays: null,
    eligibleAccountCount: 1,
    missingValuationCount: 1,
    staleValuationCount: 0,
    previousSourceAccountIds: [accountId],
    currentSourceAccountIds: [],
  },
  totals: {
    cashIncome: {
      kpi: 'cashIncome' as const,
      value: '1200.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    budgetExpenses: {
      kpi: 'budgetExpenses' as const,
      value: '-400.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    savingsInflows: {
      kpi: 'savingsInflows' as const,
      value: '50.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    savingsWithdrawals: {
      kpi: 'savingsWithdrawals' as const,
      value: '0',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    netSavingsTransfers: {
      kpi: 'netSavingsTransfers' as const,
      value: '-50.00',
      assetCode: 'EUR',
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    netSavingsRate: {
      kpi: 'netSavingsRate' as const,
      value: '-0.041666',
      assetCode: null,
      reason: null,
      pendingCount: 0,
      sourceTransactionIds: [],
      sourceTransferIds: [],
    },
    expensesByAxis: [
      {
        axis: 'ESSENTIAL' as const,
        kpi: null,
        value: '-400.00',
        assetCode: 'EUR',
        reason: null,
        pendingCount: 0,
        sourceTransactionIds: [],
        sourceTransferIds: [],
      },
    ],
    categories: [
      {
        id: expenseId,
        label: 'Voyages',
        type: 'EXPENSE' as const,
        kpi: null,
        value: '400.00',
        assetCode: 'EUR',
        reason: null,
        pendingCount: 0,
        sourceTransactionIds: [],
        sourceTransferIds: [],
      },
    ],
  },
};

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function renderPage(periodKey = '2026-03') {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <MonthlyBudgetPage periodKey={periodKey} today="2026-03-15" />
    </QueryClientProvider>,
  );
}

describe('MonthlyBudgetPage', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/budget/2026-03');
    api.readMonthlyLedger.mockReturnValue(ok(ledger));
    api.readMonthlyRecap.mockReturnValue(ok(recap));
    api.readMonthlyRecapPreferences.mockReturnValue(
      ok({ visibleCategoryIds: [expenseId], visibleAxes: ['ESSENTIAL'], version: 0 }),
    );
    api.listCategories.mockReturnValue(ok({ items: [], page: 1, perPage: 100, total: 0 }));
    api.getSession.mockReturnValue(
      ok({
        authenticated: true,
        provisioned: true,
        user: { id: 'u1', email: 'owner@example.test', displayName: 'Owner' },
        workspace: { id: 'w1', role: 'OWNER' },
      }),
    );
    api.listAccounts.mockReturnValue(ok({ items: [account], page: 1, perPage: 100, total: 1 }));
    api.readMonthlyLedgerMovements.mockReturnValue(
      ok({
        items: [
          {
            id: '00000000-0000-7000-8000-000000000004',
            transactionId: '00000000-0000-7000-8000-000000000005',
            transferId: null,
            bookedOn: '2026-03-14',
            label: 'Paie mars',
            amount: { value: '2500.00', assetCode: 'EUR' },
            direction: null,
            counterpartAccountId: null,
            counterpartAccountLabel: null,
          },
        ],
        nextCursor: null,
        hasMore: false,
        pageSize: 50,
      }),
    );
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/');
  });

  it('renders exact zero, hides categories without movement and keeps every account', async () => {
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Budget de mars 2026' })).toBeTruthy();
    expect(await screen.findByText('Salaire')).toBeTruthy();
    expect(
      screen.getAllByText(
        (_content, node) => node?.textContent === '0 €' && node.tagName === 'SPAN',
      ).length,
    ).toBeGreaterThan(0);
    expect(screen.getByText('Compte courant')).toBeTruthy();
    expect(screen.getByText('Livret vide')).toBeTruthy();
    expect(screen.getByText('Aucun mouvement')).toBeTruthy();
    expect(screen.queryByText('Voyages')).toBeNull();
    expect(screen.queryByText('Hors budget')).toBeNull();
    expect(screen.getByText('2 transactions en attente')).toBeTruthy();
  });

  it('shows the backend-owned totals in the ledger panel headers', async () => {
    renderPage();

    const income = await screen.findByRole('region', { name: 'Revenus par catégorie' });
    const expense = screen.getByRole('region', { name: 'Dépenses par catégorie' });
    const transfers = screen.getByRole('region', { name: 'Virements par compte' });

    await waitFor(() => {
      expect(within(income).getByText('Total des revenus').parentElement?.textContent).toContain(
        '1 200,00 €',
      );
      expect(within(expense).getByText('Total des dépenses').parentElement?.textContent).toContain(
        '-400,00 €',
      );
      expect(
        within(transfers).getByText('Total net des virements').parentElement?.textContent,
      ).toContain('-50,00 €');
    });
  });

  it('gives a row without movement no accordion toggle and mutes its text', async () => {
    renderPage();

    const emptyRow = (await screen.findByText('Livret vide')).closest('li')!;
    expect(
      screen.queryByRole('button', { name: 'Afficher les mouvements de Livret vide' }),
    ).toBeNull();
    expect(emptyRow.querySelector('[aria-controls]')).toBeNull();
    expect(emptyRow.className).toMatch(/rowEmpty/);
    expect(emptyRow.querySelector('button')?.getAttribute('aria-label')).toBe(
      'Ajouter un virement vers Livret vide',
    );

    const filledRow = screen.getByText('Compte courant').closest('li')!;
    expect(
      screen.getByRole('button', { name: 'Afficher les mouvements de Compte courant' }),
    ).toBeTruthy();
    expect(filledRow.className).not.toMatch(/rowEmpty/);
  });

  it('shows the empty state of a panel whose categories all have no movement', async () => {
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, incomeCategories: [] }));
    renderPage();

    const income = await screen.findByRole('region', { name: 'Revenus par catégorie' });
    expect(within(income).getByText('Aucune ligne active pour ce mois.')).toBeTruthy();
  });

  it('keeps an out-of-budget expense category that has movements', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        expenseCategories: [
          {
            ...ledger.expenseCategories[0]!,
            total: { value: '-10.00', assetCode: 'EUR', reason: null },
            movementCount: 1,
            hasMovements: true,
          },
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText('Voyages')).toBeTruthy();
    expect(screen.getByText('Hors budget')).toBeTruthy();
  });

  it('opens the transaction editor from a movement and refreshes the ledger after saving', async () => {
    const stored = {
      id: movementTransactionId,
      accountId,
      amount: { value: '2500.00', assetCode: 'EUR' },
      originalAmount: null,
      exchangeRate: null,
      nature: 'INCOME',
      state: 'BOOKED',
      source: 'MANUAL',
      sourceRef: null,
      bookedOn: '2026-03-14',
      valueOn: null,
      authorizedOn: null,
      rawLabel: 'Paie mars',
      counterparty: null,
      note: null,
      paymentMethod: null,
      mcc: null,
      maskedCard: null,
      bankReference: null,
      splits: [],
      version: 3,
      createdAt: '2026-03-14T09:00:00+01:00',
      updatedAt: '2026-03-14T09:00:00+01:00',
      voidedAt: null,
      transferId: null,
      refundOriginalId: null,
      refundOriginalLabel: null,
      refundedAmount: null,
      reviewReason: null,
      reconciliationCandidateIds: [],
      reconciledIntoId: null,
    };
    api.getTransaction.mockReturnValue(ok(stored));
    api.updateTransaction.mockImplementation(({ body }) => ok({ ...stored, ...body }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', {
        name: /^Modifier le mouvement Paie mars du .*, 2\s500,00\s€$/,
      }),
    );

    const label = await screen.findByRole('textbox', { name: 'Libellé' });
    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    expect(api.getTransaction).toHaveBeenCalledWith(
      expect.objectContaining({ path: { id: movementTransactionId } }),
    );
    const callsBeforeSave = api.readMonthlyLedger.mock.calls.length;
    const recapCallsBeforeSave = api.readMonthlyRecap.mock.calls.length;
    fireEvent.change(label, { target: { value: 'Paie mars corrigée' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    expect(api.updateTransaction.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({ path: { id: movementTransactionId } }),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() =>
      expect(api.readMonthlyLedger.mock.calls.length).toBeGreaterThan(callsBeforeSave),
    );
    await waitFor(() =>
      expect(api.readMonthlyLedgerMovements.mock.calls.length).toBeGreaterThan(1),
    );
    await waitFor(() =>
      expect(api.readMonthlyRecap.mock.calls.length).toBeGreaterThan(recapCallsBeforeSave),
    );
    expect(await screen.findByText('Le mouvement a été enregistré.')).toBeTruthy();
  });

  it('offers a retry when the movement cannot be loaded', async () => {
    api.getTransaction.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', { name: /^Modifier le mouvement Paie mars/ }),
    );

    const dialog = await screen.findByRole('dialog', { name: 'Modifier la transaction' });
    expect(await within(dialog).findByRole('alert')).toBeTruthy();
    expect(within(dialog).getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('does not turn a movement into an edit button while the period is closed', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );

    expect(await screen.findByText('Paie mars')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Modifier le mouvement/ })).toBeNull();
  });

  it('does not fetch row movements before its accessible accordion is opened', async () => {
    renderPage();

    const toggle = await screen.findByRole('button', {
      name: 'Afficher les mouvements de Salaire',
    });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(api.readMonthlyLedgerMovements).not.toHaveBeenCalled();

    fireEvent.click(toggle);

    await waitFor(() => expect(api.readMonthlyLedgerMovements).toHaveBeenCalledOnce());
    expect(api.readMonthlyLedgerMovements).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { id: incomeId, kind: 'income' },
        query: { month: '2026-03' },
      }),
    );
    expect(await screen.findByText('Paie mars')).toBeTruthy();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
  });

  it('continues an expanded row from the server cursor without loading every history', async () => {
    api.readMonthlyLedgerMovements
      .mockReturnValueOnce(
        ok({
          items: [
            {
              id: '00000000-0000-7000-8000-000000000004',
              transactionId: '00000000-0000-7000-8000-000000000005',
              transferId: null,
              bookedOn: '2026-03-14',
              label: 'Paie mars',
              amount: { value: '2500.00', assetCode: 'EUR' },
              direction: null,
              counterpartAccountId: null,
              counterpartAccountLabel: null,
            },
          ],
          nextCursor: 'next-page',
          hasMore: true,
          pageSize: 50,
        }),
      )
      .mockReturnValueOnce(ok({ items: [], nextCursor: null, hasMore: false, pageSize: 50 }));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Salaire' }),
    );
    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements suivants' }),
    );

    await waitFor(() => expect(api.readMonthlyLedgerMovements).toHaveBeenCalledTimes(2));
    expect(api.readMonthlyLedgerMovements.mock.calls[1]?.[0]).toEqual(
      expect.objectContaining({ query: { cursor: 'next-page', month: '2026-03' } }),
    );
  });

  it('preserves the selected axis in month links and ledger reads', async () => {
    window.history.replaceState({}, '', '/budget/2026-03?axis=ESSENTIAL');
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, axis: 'ESSENTIAL' as const }));
    renderPage();

    expect(((await screen.findByLabelText('Axe analytique')) as HTMLSelectElement).value).toBe(
      'ESSENTIAL',
    );
    expect(screen.getByRole('link', { name: 'Février' }).getAttribute('href')).toBe(
      '/budget/2026-02?axis=ESSENTIAL',
    );
    expect(api.readMonthlyLedger).toHaveBeenCalledWith(
      expect.objectContaining({ query: { axis: 'ESSENTIAL', month: '2026-03' } }),
    );
  });

  it('opens the shared transaction modal with month, nature and category prefilled', async () => {
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' }));

    expect(await screen.findByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect(((await screen.findByLabelText('Date comptable')) as HTMLInputElement).value).toBe(
      '2026-03-15',
    );
    expect((screen.getByLabelText('Nature') as HTMLSelectElement).value).toBe('INCOME');
    expect((screen.getByRole('combobox', { name: 'Compte' }) as HTMLSelectElement).value).toBe('');
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Salaire',
    );
  });

  it('adds a transaction from an empty panel header with no category preselected', async () => {
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, expenseCategories: [] }));
    renderPage();

    const expense = await screen.findByRole('region', { name: 'Dépenses par catégorie' });
    expect(within(expense).getByText('Aucune ligne active pour ce mois.')).toBeTruthy();
    fireEvent.click(within(expense).getByRole('button', { name: 'Ajouter une dépense' }));

    expect(await screen.findByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect(((await screen.findByLabelText('Nature')) as HTMLSelectElement).value).toBe('EXPENSE');
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      '',
    );
  });

  it('opens the transaction modal from the header button', async () => {
    renderPage();

    await screen.findByRole('region', { name: 'Revenus par catégorie' });
    fireEvent.click(screen.getByRole('button', { name: 'Créer une transaction' }));

    expect(await screen.findByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      '',
    );
  });

  it('disables the header and panel free-add buttons while the period is closed', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    await screen.findByRole('region', { name: 'Revenus par catégorie' });
    const header = screen.getByRole('button', { name: 'Créer une transaction' });
    expect(header.getAttribute('disabled')).not.toBeNull();
    expect(
      screen.getByRole('button', { name: 'Ajouter un revenu' }).getAttribute('disabled'),
    ).not.toBeNull();
  });

  it('opens the shared transfer modal with destination and date prefilled but no source', async () => {
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Ajouter un virement vers Compte courant' }),
    );

    expect(await screen.findByRole('dialog', { name: 'Nouveau virement' })).toBeTruthy();
    expect(((await screen.findByLabelText('Date')) as HTMLInputElement).value).toBe('2026-03-15');
    expect((screen.getByLabelText('Compte source') as HTMLSelectElement).value).toBe('');
    expect((screen.getByLabelText('Compte de destination') as HTMLSelectElement).value).toBe(
      accountId,
    );
  });

  it('keeps closed-period creation disabled with a readable reason', async () => {
    api.readMonthlyLedger.mockReturnValue(
      ok({
        ...ledger,
        closed: true,
        actionsAllowed: false,
        actionReason: 'PERIOD_CLOSED' as const,
      }),
    );
    renderPage();

    const add = await screen.findByRole('button', { name: 'Ajouter un revenu dans Salaire' });
    expect(add.getAttribute('disabled')).not.toBeNull();
    expect(add.getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getAllByText('Cette période est clôturée.').length).toBeGreaterThan(0);
  });

  it('keeps the year selector and month tabs when the ledger fails to load', async () => {
    api.readMonthlyLedger.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();

    expect((await screen.findByRole('alert')).textContent).toContain('Réessayer');
    expect(screen.getByLabelText('Année')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
  });

  it('keeps the month tabs while the ledger is loading', () => {
    api.readMonthlyLedger.mockReturnValue(new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('status')).toBeTruthy();
    expect(screen.getByLabelText('Année')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
  });

  it('composes the transfer sentence from direction and counterpart account', async () => {
    api.readMonthlyLedgerMovements.mockReturnValue(
      ok({
        items: [
          {
            id: '00000000-0000-7000-8000-000000000006',
            transactionId: '00000000-0000-7000-8000-000000000007',
            transferId: '00000000-0000-7000-8000-000000000008',
            bookedOn: '2026-03-14',
            label: 'Livret A',
            amount: { value: '-300.00', assetCode: 'EUR' },
            direction: 'OUT',
            counterpartAccountId: '00000000-0000-7000-8000-000000000009',
            counterpartAccountLabel: 'Livret A',
          },
        ],
        nextCursor: null,
        hasMore: false,
        pageSize: 50,
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Afficher les mouvements de Compte courant' }),
    );

    expect(await screen.findByText('Sortie vers Livret A')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Modifier le mouvement/ })).toBeNull();
  });

  it('disables the transfer action for an account that is not active', async () => {
    api.listAccounts.mockReturnValue(
      ok({ items: [{ ...account, status: 'CLOSED' }], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    await waitFor(() => {
      const add = screen.getByRole('button', {
        name: 'Ajouter un virement vers Compte courant',
      });
      expect(add.getAttribute('disabled')).not.toBeNull();
      expect(add.getAttribute('aria-describedby')).toBeTruthy();
    });
    expect(
      screen.getAllByText("Ce compte est clos : aucun virement n'est possible.").length,
    ).toBeGreaterThan(0);
  });

  it('uses the ledger timezone, not a hard-coded one, to decide the current month', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-03-31T20:00:00Z'));
    api.readMonthlyLedger.mockReturnValue(ok({ ...ledger, timezone: 'Pacific/Auckland' }));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    try {
      render(
        <QueryClientProvider client={client}>
          <MonthlyBudgetPage periodKey="2026-03" />
        </QueryClientProvider>,
      );

      expect(await screen.findByRole('link', { name: 'Avril' })).toBeTruthy();
    } finally {
      vi.useRealTimers();
    }
  });

  it('shows provisional non-calculable recap values, links accounts, and saves owner preferences', async () => {
    api.listCategories.mockReturnValue(
      ok({
        items: [{ id: expenseId, label: 'Voyages', type: 'EXPENSE' }],
        page: 1,
        perPage: 100,
        total: 1,
      }),
    );
    api.saveMonthlyRecapPreferences.mockReturnValue(
      ok({ visibleCategoryIds: [], visibleAxes: ['ESSENTIAL'], version: 1 }),
    );
    renderPage();

    expect(await screen.findByText('Valeurs provisoires à la date du jour.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Compte courant' }).getAttribute('href')).toBe(
      `/accounts/${accountId}`,
    );
    expect(screen.getAllByText('Non calculable').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(screen.getAllByText('Voyages').length).toBeGreaterThan(0);
    fireEvent.click(await within(dialog).findByLabelText('Voyages'));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() =>
      expect(api.saveMonthlyRecapPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { visibleCategoryIds: [], visibleAxes: ['ESSENTIAL'], version: 0 },
        }),
      ),
    );
  });

  it('loads every income and expense category page for recap preferences', async () => {
    const firstPage = Array.from({ length: 100 }, (_, index) => ({
      id: `category-${index + 1}`,
      label: `Category ${index + 1}`,
      type: 'EXPENSE' as const,
    }));
    const incomeCategory = { id: 'category-101', label: 'Income 101', type: 'INCOME' as const };
    api.listCategories.mockImplementation(({ query }) =>
      ok(
        query?.page === 2
          ? { items: [incomeCategory], page: 2, perPage: 100, total: 101 }
          : { items: firstPage, page: 1, perPage: 100, total: 101 },
      ),
    );
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        totals: {
          ...recap.totals,
          categories: [
            ...recap.totals.categories,
            { ...recap.totals.categories[0], id: incomeCategory.id },
          ],
        },
      }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(await within(dialog).findByLabelText('Income 101')).toBeTruthy();
    expect(api.listCategories).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ page: 2, perPage: 100 }) }),
    );
  });

  it('offers an archived category when it is represented by the recap', async () => {
    const archivedCategory = {
      id: expenseId,
      label: 'Voyages archivés',
      archived: true,
      type: 'EXPENSE' as const,
    };
    api.listCategories.mockReturnValue(
      ok({ items: [archivedCategory], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    expect(await within(dialog).findByLabelText('Voyages archivés')).toBeTruthy();
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ includeArchived: true }) }),
    );
  });

  it('reports N−1 valuation staleness even when the current value is current', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        netWorth: {
          ...recap.netWorth,
          current: recap.netWorth.previous,
          currentReason: null,
          difference: recap.netWorth.previous,
          differenceReason: null,
          changePercentDisplay: '0',
          changeReason: null,
          previousQuality: 'STALE' as const,
          previousStalestAgeDays: 3,
          previousStaleValuationCount: 1,
          quality: 'CURRENT' as const,
          missingValuationCount: 0,
          staleValuationCount: 0,
        },
      }),
    );
    renderPage();

    expect(await screen.findByText('Au N−1, 1 valorisation est ancienne (3 jours).')).toBeTruthy();
  });

  it('reloads preferences after a stale version refusal', async () => {
    api.saveMonthlyRecapPreferences.mockReturnValue(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 409 }) }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Enregistrer' }));
    expect(
      await within(dialog).findByRole('button', { name: 'Recharger les préférences' }),
    ).toBeTruthy();
    const readsBeforeReload = api.readMonthlyRecapPreferences.mock.calls.length;
    fireEvent.click(within(dialog).getByRole('button', { name: 'Recharger les préférences' }));
    await waitFor(() =>
      expect(api.readMonthlyRecapPreferences.mock.calls.length).toBeGreaterThan(readsBeforeReload),
    );
  });

  it('opens source transactions by label and amount for configured axes and categories', async () => {
    api.readMonthlyRecap.mockReturnValue(
      ok({
        ...recap,
        totals: {
          ...recap.totals,
          expensesByAxis: [
            {
              ...recap.totals.expensesByAxis[0],
              sourceTransactionIds: ['axis-transaction'],
              sourceTransactions: [
                {
                  id: 'axis-transaction',
                  bookedOn: '2026-03-01',
                  label: 'Loyer',
                  amount: { value: '-400.00', assetCode: 'EUR' },
                  state: 'BOOKED',
                },
              ],
            },
          ],
          categories: [
            {
              ...recap.totals.categories[0],
              sourceTransactionIds: ['category-transaction'],
              sourceTransactions: [
                {
                  id: 'category-transaction',
                  bookedOn: '2026-03-02',
                  label: 'Courses',
                  amount: { value: '-42.50', assetCode: 'EUR' },
                  state: 'BOOKED',
                },
              ],
            },
          ],
        },
      }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Expliquer : Essentiel' }));
    const axisDialog = await screen.findByRole('dialog', { name: 'Expliquer : Essentiel' });
    expect(within(axisDialog).getByText('Loyer')).toBeTruthy();
    expect(axisDialog.textContent).toContain('-400,00 €');
    expect(within(axisDialog).queryByText('axis-transaction')).toBeNull();
    fireEvent.click(within(axisDialog).getByRole('button', { name: 'Fermer' }));
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Voyages' }));
    const categoryDialog = await screen.findByRole('dialog', { name: 'Expliquer : Voyages' });
    expect(within(categoryDialog).getByText('Courses')).toBeTruthy();
    expect(categoryDialog.textContent).toContain('-42,50 €');
    expect(within(categoryDialog).queryByText('category-transaction')).toBeNull();
  });

  it('retries loading recap preferences and preference categories after errors', async () => {
    api.readMonthlyRecapPreferences.mockReturnValueOnce(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();
    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    const preferenceReads = api.readMonthlyRecapPreferences.mock.calls.length;
    fireEvent.click(retry);
    await waitFor(() =>
      expect(api.readMonthlyRecapPreferences.mock.calls.length).toBeGreaterThan(preferenceReads),
    );

    api.listCategories.mockReturnValueOnce(
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Configurer l’affichage' }));
    const dialog = await screen.findByRole('dialog', { name: 'Affichage de la synthèse' });
    const categoryReads = api.listCategories.mock.calls.length;
    fireEvent.click(await within(dialog).findByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(api.listCategories.mock.calls.length).toBeGreaterThan(categoryReads),
    );
  });

  it('shows a recoverable error for malformed and future month routes without querying', () => {
    const { rerender } = renderPage('not-a-month');

    expect(screen.getByRole('alert').textContent).toContain('Période invalide');
    expect(api.readMonthlyLedger).not.toHaveBeenCalled();

    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <MonthlyBudgetPage periodKey="2026-04" today="2026-03-15" />
      </QueryClientProvider>,
    );
    expect(screen.getByRole('alert').textContent).toContain('Période future');
  });
});
