import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyBudgetPage } from './MonthlyBudgetPage';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  getTransaction: vi.fn(),
  listAccounts: vi.fn(),
  readMonthlyLedger: vi.fn(),
  readMonthlyLedgerMovements: vi.fn(),
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
