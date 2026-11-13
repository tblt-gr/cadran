import type { Account, Category, Transaction, TransactionFacets } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import { TransactionsPage } from './TransactionsPage';

const api = vi.hoisted(() => ({
  createCategory: vi.fn(),
  createTransaction: vi.fn(),
  createTransactionRefund: vi.fn(),
  getTransactionRefundable: vi.fn(),
  createTransfer: vi.fn(),
  recordPortfolioOperation: vi.fn(),
  duplicateTransaction: vi.fn(),
  getSession: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  listTransactions: vi.fn(),
  readPeriodStatus: vi.fn(),
  readTransactionFacets: vi.fn(),
  updateTransaction: vi.fn(),
  voidTransaction: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

beforeEach(() => {
  api.getSession.mockResolvedValue({
    data: {
      provisioned: true,
      authenticated: true,
      setupRequired: false,
      user: null,
      workspace: null,
    },
    response: { ok: true, status: 200 },
  });
  api.readPeriodStatus.mockResolvedValue({
    data: { period: '2026-08', closed: false, ended: true, closure: null, blockers: [] },
    response: { ok: true, status: 200 },
  });
  // Counts stay out of the way unless a test asks for them: an unreadable answer hides them.
  api.readTransactionFacets.mockImplementation(() =>
    Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
  );
});

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Compte courant',
  isPrimary: true,
  kind: 'CURRENT',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const expenseCategory = {
  id: '00000000-0000-7000-8000-0000000000c1',
  type: 'EXPENSE',
  label: 'Courses',
  archivedAt: null,
} as Category;

const transaction: Transaction = {
  id: '00000000-0000-7000-8000-0000000000f1',
  accountId: account.id,
  amount: { value: '-42.90', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  sourceRef: null,
  bookedOn: '2026-03-14',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CB CARREFOUR 1234',
  userLabel: null,
  displayLabel: 'CB CARREFOUR 1234',
  counterparty: 'Carrefour',
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 1,
  createdAt: '2026-03-14T09:12:04+01:00',
  updatedAt: '2026-03-14T09:12:04+01:00',
  voidedAt: null,
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  reviewReason: null,
  autoBook: false,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <TransactionsPage />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

describe('TransactionsPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.localStorage.clear();
  });

  it('keeps the register skeleton until account names have resolved', async () => {
    let resolve!: (value: unknown) => void;
    const accountRead = new Promise((done) => {
      resolve = done;
    });
    api.listAccounts.mockReturnValue(accountRead);
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();
    await waitFor(() => expect(api.listAccounts).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId('transaction-day-list-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.queryByText(transaction.displayLabel)).toBeNull();
    resolve(await success({ items: [account], page: 1, pageSize: 100, total: 1 }));
    expect(await screen.findByText(transaction.displayLabel)).toBeTruthy();
    expect(screen.queryByText('Compte indisponible')).toBeNull();
  });

  it('offers recovery when account names fail without hiding the server movements', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
    api.listAccounts.mockRejectedValue(new Error('offline'));
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Les noms de compte');
    expect(await screen.findByText(transaction.displayLabel)).toBeTruthy();
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(screen.queryByText('Compte indisponible')).toBeNull();
  });

  it('closes the shared transfer modal once a transfer into a portfolio is saved, with no client-side deposit', async () => {
    const destination = {
      ...account,
      id: 'portfolio',
      label: 'Titres',
      kind: 'PORTFOLIO' as const,
      valuationMode: 'PORTFOLIO' as const,
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, destination], page: 1, pageSize: 100, total: 2 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.createTransfer.mockImplementation(() =>
      success({
        target: {
          ...transaction,
          id: 'incoming',
          accountId: destination.id,
          amount: { value: '1500.00', assetCode: 'EUR' },
          bookedOn: '2026-03-10',
        },
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouveau virement' }));
    const dialog = screen.getByRole('dialog', { name: 'Nouveau virement' });
    expect((within(dialog).getByLabelText('Compte source') as HTMLSelectElement).disabled).toBe(
      false,
    );
    expect(
      (within(dialog).getByLabelText('Compte de destination') as HTMLSelectElement).disabled,
    ).toBe(false);
    fireEvent.change(within(dialog).getByLabelText('Montant (EUR)'), {
      target: { value: '1500.00' },
    });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: 'Investissement mensuel' },
    });
    fireEvent.change(within(dialog).getByLabelText('Compte de destination'), {
      target: { value: destination.id },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer le virement' }));
    await waitFor(() =>
      expect(screen.queryByRole('dialog', { name: 'Nouveau virement' })).toBeNull(),
    );
    expect(api.createTransfer).toHaveBeenCalledOnce();
    expect(api.recordPortfolioOperation).not.toHaveBeenCalled();
  });

  it('replaces its sole cold-load status and keeps transactions during a refetch', async () => {
    let resolveTransactions: ((value: unknown) => void) | undefined;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveTransactions = resolve;
        }),
    );
    const { queryClient } = renderPage();

    expect(screen.getByTestId('transaction-day-list-skeleton')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Sélectionner' })).toHaveLength(1);
    expect(
      (screen.getByRole('button', { name: 'Sélectionner' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(screen.getAllByRole('status')).toHaveLength(1);
    resolveTransactions?.({
      data: { items: [transaction], nextCursor: null },
      response: new Response('{}', { status: 200 }),
    });
    expect(await screen.findByText(transaction.rawLabel)).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Sélectionner' }) as HTMLButtonElement).disabled,
    ).toBe(false);
    expect(screen.queryByTestId('transaction-day-list-skeleton')).toBeNull();

    api.listTransactions.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['transactions'] });
    await waitFor(() => expect(api.listTransactions).toHaveBeenCalledTimes(2));
    expect(screen.getByText(transaction.rawLabel)).toBeTruthy();
    expect(screen.queryByTestId('transaction-day-list-skeleton')).toBeNull();
  });

  it('keeps the search and the filter column as disabled real controls while loading', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    expect(screen.getByRole('searchbox')).toHaveProperty('disabled', true);
    expect(screen.getByRole('button', { name: 'Filtres' })).toHaveProperty('disabled', true);
    expect(screen.getByRole('checkbox', { name: 'Sans catégorie' }).matches(':disabled')).toBe(
      true,
    );
    expect(screen.getByTestId('transaction-day-list-skeleton')).toBeTruthy();
  });

  it('keeps the filter bar usable when the list fails to load', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
    );
    renderPage();

    await screen.findByRole('heading', { name: /indisponible/ });
    expect(screen.getByRole('searchbox')).toHaveProperty('disabled', false);
    expect(screen.getByRole('button', { name: 'Filtres' })).toHaveProperty('disabled', false);
  });

  it('shows no filter icon in the header and lays the filters out in a column, not a modal', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();
    await screen.findByText(transaction.rawLabel);

    expect(screen.getByRole('button', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouveau virement' })).toBeTruthy();
    expect(document.querySelector('[title="Filtres"]')).toBeNull();
    expect(screen.getByRole('complementary', { name: 'Filtres' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Filtres' }).getAttribute('aria-expanded')).toBe(
      'true',
    );

    fireEvent.click(screen.getByRole('button', { name: 'Filtres' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.queryByRole('complementary', { name: 'Filtres' })).toBeNull();
  });

  it('leaves the month and the views to the filters: no month stepper, quick views or overflow menu', async () => {
    window.history.replaceState({}, '', '/transactions?period=month&month=2026-11');
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();
    await screen.findByText(transaction.rawLabel);

    expect(screen.queryByRole('button', { name: 'Mois précédent' })).toBeNull();
    expect(screen.queryByRole('group', { name: 'Vues courantes' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Autres actions' })).toBeNull();
    window.history.replaceState({}, '', '/transactions');
  });

  it('offers every category, expenses first, whatever the nature or the sign', async () => {
    const incomeCategory = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c2',
      type: 'INCOME',
      label: 'Salaire',
    } as Category;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    // The server lists income first here: the picker still leads with what the sign expects.
    api.listCategories.mockImplementation(() =>
      success({ items: [incomeCategory, expenseCategory], page: 1, pageSize: 50, total: 2 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));

    const expenses = await screen.findByRole('group', { name: 'Dépenses' });
    const incomes = screen.getByRole('group', { name: 'Revenus' });
    expect(within(expenses).getByRole('option', { name: 'Courses' })).toBeTruthy();
    expect(within(incomes).getByRole('option', { name: 'Salaire' })).toBeTruthy();
    expect(
      expenses.compareDocumentPosition(incomes) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ type: undefined }) }),
    );

    // Switching the nature keeps both types on offer, income now first.
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Catégorie' }), { key: 'Escape' });
    fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
    fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));
    // Scoped to the dialog: the filter bar's native multi-select for accounts also carries
    // the implicit "listbox" role, so an unscoped query would match both.
    const dialog = screen.getByRole('dialog', { name: 'Nouvelle transaction' });
    const listbox = await within(dialog).findByRole('listbox');
    expect(
      within(listbox)
        .getAllByRole('group')
        .map((group) => document.getElementById(group.getAttribute('aria-labelledby') ?? '')),
    ).toEqual([within(listbox).getByText('Revenus'), within(listbox).getByText('Dépenses')]);

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Catégorie' }), { key: 'Escape' });
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
  });

  it('keeps a category whose type contradicts the sign and says why it is refused', async () => {
    const incomeCategory = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c2',
      type: 'INCOME',
      label: 'Salaire',
    } as Category;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [expenseCategory, incomeCategory], page: 1, pageSize: 50, total: 2 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-1500.00' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'VIR EMPLOYEUR' } });
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.focus(picker);
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Salaire' }));

    expect((picker as HTMLInputElement).value).toBe('Salaire');
    expect(picker.getAttribute('aria-invalid')).toBe('true');
    const message = 'Catégorie de revenu : le montant doit être positif, comme toute entrée.';
    expect(screen.getByText(message)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(api.createTransaction).not.toHaveBeenCalled();

    // The expense nature implies the sign, so typing the amount unsigned changes nothing.
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '1500.00' } });
    expect(screen.getByText(message)).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
    expect(screen.queryByText(message)).toBeNull();
    expect((picker as HTMLInputElement).value).toBe('Salaire');
  });

  it('quick-creates and selects a category without changing the transaction draft', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    const created = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c9',
      label: 'Boulangerie',
    };
    api.createCategory.mockImplementation(({ body }) => success({ ...created, ...body }, 201));
    api.createTransaction.mockImplementation(({ body }) =>
      success({ ...transaction, ...body, splits: [] }, 201),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-42.90' } });
    fireEvent.change(screen.getByLabelText('Date de valeur'), { target: { value: '2026-09-09' } });
    fireEvent.change(screen.getByLabelText('Libellé'), {
      target: { value: 'CB BOULANGERIE 1234' },
    });
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Petit-déjeuner' } });
    fireEvent.change(screen.getByLabelText('Moyen de paiement'), { target: { value: 'CARD' } });
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.change(picker, { target: { value: 'Boulangerie' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Boulangerie »' }));

    const quickDialog = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.click(within(quickDialog).getByRole('button', { name: 'Créer' }));

    await waitFor(() => expect(api.createCategory).toHaveBeenCalledOnce());
    expect(api.createCategory.mock.calls[0]?.[0].body).toEqual({
      type: 'EXPENSE',
      label: 'Boulangerie',
      parentId: null,
      icon: null,
      color: null,
      defaultAnalyticAxes: [],
      budgetIncluded: true,
      sortOrder: 0,
    });
    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    await waitFor(() => expect(document.activeElement).toBe(picker));
    expect((picker as HTMLInputElement).value).toBe('Boulangerie');
    // The list stays closed on focus return, so Enter cannot pick another option.
    expect(picker.getAttribute('aria-expanded')).toBe('false');
    fireEvent.keyDown(picker, { key: 'Enter' });
    expect((picker as HTMLInputElement).value).toBe('Boulangerie');

    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).getByRole('button', {
        name: 'Enregistrer',
      }),
    );
    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledOnce());
    expect(api.createTransaction.mock.calls[0]?.[0].body).toMatchObject({
      amount: { value: '-42.90', assetCode: 'EUR' },
      valueOn: '2026-09-09',
      rawLabel: 'CB BOULANGERIE 1234',
      note: 'Petit-déjeuner',
      paymentMethod: 'CARD',
      categoryId: created.id,
    });
  });

  describe('amount entry convention', () => {
    async function openNewTransaction() {
      api.listAccounts.mockImplementation(() =>
        success({ items: [account], page: 1, pageSize: 100, total: 1 }),
      );
      api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
      api.listCategories.mockImplementation(() =>
        success({ items: [], page: 1, pageSize: 50, total: 0 }),
      );
      api.createTransaction.mockImplementation(({ body }) =>
        success({ ...transaction, ...body, splits: [] }, 201),
      );
      renderPage();
      fireEvent.click(
        await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
      );
      fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'CB TEST' } });
    }

    async function submittedAmount(): Promise<string> {
      fireEvent.click(
        within(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).getByRole('button', {
          name: 'Enregistrer',
        }),
      );
      await waitFor(() => expect(api.createTransaction).toHaveBeenCalledOnce());

      return api.createTransaction.mock.calls[0]![0].body.amount.value;
    }

    it('submits an unsigned expense negative and shows the signed result first', async () => {
      await openNewTransaction();
      const field = screen.getByLabelText('Montant');
      fireEvent.change(field, { target: { value: '42.10' } });

      const hint = screen.getByText(/Sortie enregistrée/);
      expect(hint.textContent).toMatch(/-42,10/);
      expect(field.getAttribute('aria-describedby')).toContain(hint.id);
      expect(await submittedAmount()).toBe('-42.10');
    });

    it('never double-negates a minus typed on an expense', async () => {
      await openNewTransaction();
      fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-42.10' } });

      expect(await submittedAmount()).toBe('-42.10');
    });

    it('keeps an income positive', async () => {
      await openNewTransaction();
      fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
      fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '42.10' } });

      expect(screen.getByText(/Entrée enregistrée/)).toBeTruthy();
      expect(await submittedAmount()).toBe('42.10');
    });

    it('refuses a minus typed on an income', async () => {
      await openNewTransaction();
      fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
      fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-42.10' } });
      fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

      expect(api.createTransaction).not.toHaveBeenCalled();
    });

    it('does not guess the sign of an adjustment', async () => {
      await openNewTransaction();
      fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'ADJUSTMENT' } });
      fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '42.10' } });

      expect(await submittedAmount()).toBe('42.10');
    });

    it('still refuses zero', async () => {
      await openNewTransaction();
      fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '0.00' } });
      fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

      expect(api.createTransaction).not.toHaveBeenCalled();
    });

    it('edits an existing expense with the same unsigned convention', async () => {
      api.listAccounts.mockImplementation(() =>
        success({ items: [account], page: 1, pageSize: 100, total: 1 }),
      );
      api.listTransactions.mockImplementation(() =>
        success({ items: [transaction], nextCursor: null }),
      );
      api.listCategories.mockImplementation(() =>
        success({ items: [], page: 1, pageSize: 50, total: 0 }),
      );
      api.updateTransaction.mockImplementation(({ body }) =>
        success({ ...transaction, ...body, splits: [] }),
      );
      renderPage();

      fireEvent.click(
        await screen.findByRole('button', {
          name: 'Actions de la transaction « CB CARREFOUR 1234 »',
        }),
      );
      fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

      expect((screen.getByLabelText('Montant') as HTMLInputElement).value).toBe('42.90');
      expect(screen.getByText(/Sortie enregistrée/).textContent).toMatch(/-42,90/);
      fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
      await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
      expect(api.updateTransaction.mock.calls[0]![0].body.amount.value).toBe('-42.90');
    });
  });

  it('keeps quick-create errors inside the nested dialog and leaves the draft untouched', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.createCategory.mockResolvedValue({
      error: { type: '/problems/category-label-conflict' },
      response: new Response('{}', { status: 409 }),
    });
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-12.34' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'ACHAT TEST' } });
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.change(picker, { target: { value: 'Courses' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Courses »' }));
    const quickDialog = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.click(within(quickDialog).getByRole('button', { name: 'Créer' }));

    const alert = await within(quickDialog).findByRole('alert');
    expect(alert.textContent).toContain('Ce libellé existe déjà sous ce parent.');
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    expect((screen.getByLabelText('Montant') as HTMLInputElement).value).toBe('-12.34');
    expect(
      (
        within(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).getByLabelText(
          'Libellé',
        ) as HTMLInputElement
      ).value,
    ).toBe('ACHAT TEST');
    expect((picker as HTMLInputElement).value).toBe('Courses');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    await waitFor(() => expect(document.activeElement).toBe(picker));
  });

  it('prefills the axes of the picked category, resets them on a category change and sends an override', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    const abonnement = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c2',
      label: 'Abonnement',
      defaultAnalyticAxes: ['FIXED', 'ESSENTIAL'],
    } as Category;
    const loisirs = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c3',
      label: 'Loisirs',
      defaultAnalyticAxes: ['DISCRETIONARY'],
    } as Category;
    api.listCategories.mockImplementation(() =>
      success({ items: [abonnement, loisirs], page: 1, pageSize: 50, total: 2 }),
    );
    api.createTransaction.mockImplementation(({ body }) =>
      success({ ...transaction, ...body, splits: [] }, 201),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-9.99' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'ABONNEMENT' } });
    expect(screen.queryByRole('group', { name: 'Axes analytiques de la transaction' })).toBeNull();

    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.change(picker, { target: { value: 'Abonn' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: /Abonnement/ }));
    expect(await screen.findByText(/Essentiel, Fixe|Fixe, Essentiel/)).toBeTruthy();

    fireEvent.focus(picker);
    fireEvent.change(picker, { target: { value: 'Loisirs' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: /^Loisirs/ }));
    expect(await screen.findByText(/^Discrétionnaire.*ceux de la catégorie/)).toBeTruthy();

    const group = screen.getByRole('group', { name: 'Axes analytiques de la transaction' });
    fireEvent.click(within(group).getByLabelText('Discrétionnaire'));
    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).getByRole('button', {
        name: 'Enregistrer',
      }),
    );

    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledOnce());
    const body = api.createTransaction.mock.calls[0]?.[0].body;
    expect(body.categoryId).toBeNull();
    expect(body.splits).toEqual([
      {
        categoryId: loisirs.id,
        amount: { value: '-9.99', assetCode: 'EUR' },
        analyticAxes: [],
        note: null,
      },
    ]);
  });

  it('cancels quick creation without touching the draft or the transaction focus trap', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    const transactionDialog = screen.getByRole('dialog', { name: 'Nouvelle transaction' });
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-7.10' } });
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'Café' } });
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });

    fireEvent.change(picker, { target: { value: 'Café' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Café »' }));
    expect(transactionDialog.hasAttribute('inert')).toBe(true);
    fireEvent.click(
      within(screen.getByRole('dialog', { name: 'Nouvelle catégorie' })).getByRole('button', {
        name: 'Fermer',
      }),
    );

    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(transactionDialog.hasAttribute('inert')).toBe(false);
    await waitFor(() => expect(document.activeElement).toBe(picker));

    fireEvent.mouseDown(picker);
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Café »' }));
    const quickDialog = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.mouseDown(quickDialog.parentElement as HTMLElement);

    expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull();
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBe(transactionDialog);
    expect((screen.getByLabelText('Montant') as HTMLInputElement).value).toBe('-7.10');
    expect((screen.getByLabelText('Note') as HTMLTextAreaElement).value).toBe('Café');
    expect((picker as HTMLInputElement).value).toBe('Café');
    expect(api.createCategory).not.toHaveBeenCalled();
  });

  it('prefills the income type for an income', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '1500.00' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Catégorie' }), {
      target: { value: 'Salaire' },
    });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Salaire »' }));

    const quickDialog = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    expect(
      (within(quickDialog).getByRole('combobox', { name: 'Type' }) as HTMLSelectElement).value,
    ).toBe('INCOME');
  });

  it('selects a quick-created category of the other type and flags the sign', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.createCategory.mockImplementation(({ body }) =>
      success({ ...expenseCategory, ...body, id: '00000000-0000-7000-8000-0000000000c9' }, 201),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-30.00' } });
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.change(picker, { target: { value: 'Prime' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Prime »' }));
    const quickDialog = screen.getByRole('dialog', { name: 'Nouvelle catégorie' });
    fireEvent.change(within(quickDialog).getByRole('combobox', { name: /^Type/ }), {
      target: { value: 'INCOME' },
    });
    fireEvent.click(within(quickDialog).getByRole('button', { name: 'Créer' }));

    await waitFor(() =>
      expect(screen.queryByRole('dialog', { name: 'Nouvelle catégorie' })).toBeNull(),
    );
    expect(api.createCategory.mock.calls[0]?.[0].body).toMatchObject({
      label: 'Prime',
      type: 'INCOME',
    });
    expect((picker as HTMLInputElement).value).toBe('Prime');
    expect(
      within(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).getByText(
        'Catégorie de revenu : le montant doit être positif, comme toute entrée.',
      ),
    ).toBeTruthy();
  });

  it('issues one category request for two rapid quick-create submissions', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.createCategory.mockImplementation(() => new Promise(() => undefined));
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    const picker = screen.getByRole('combobox', { name: 'Catégorie' });
    fireEvent.change(picker, { target: { value: 'Boulangerie' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Créer « Boulangerie »' }));
    const form = within(screen.getByRole('dialog', { name: 'Nouvelle catégorie' }))
      .getByRole('button', { name: 'Créer' })
      .closest('form') as HTMLFormElement;

    fireEvent.submit(form);
    fireEvent.submit(form);

    await waitFor(() => expect(api.createCategory).toHaveBeenCalledOnce());
  });

  it('covers the empty state and creates a signed expense', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.createTransaction.mockImplementation(({ body }) =>
      success({ ...transaction, ...body, splits: [] }, 201),
    );
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Aucune transaction' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la première transaction' }));
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '-42.90' } });
    fireEvent.change(screen.getByLabelText('Libellé'), {
      target: { value: 'CB CARREFOUR 1234' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.createTransaction).toHaveBeenCalledOnce());
    expect(api.createTransaction.mock.calls[0]?.[0].body).toMatchObject({
      accountId: account.id,
      amount: { value: '-42.90', assetCode: 'EUR' },
      nature: 'EXPENSE',
      rawLabel: 'CB CARREFOUR 1234',
    });
    expect(await screen.findByText('La transaction a été enregistrée.')).toBeTruthy();
  });

  it('folds the rarely used fields, names the filled ones and opens them on an error', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Nouvelle transaction' });
    const advanced = within(dialog)
      .getByText('Champs avancés')
      .closest('details') as HTMLDetailsElement;
    expect(advanced.open).toBe(false);
    for (const label of [
      'Compte',
      'Date comptable',
      'Montant',
      'Nature',
      'Libellé',
      'Moyen de paiement',
    ]) {
      expect(advanced.contains(within(dialog).getByLabelText(label))).toBe(false);
    }
    expect(advanced.contains(within(dialog).getByRole('combobox', { name: 'Catégorie' }))).toBe(
      false,
    );
    for (const label of ['Tiers', 'Note', 'État', 'Date de valeur', 'Date d’autorisation']) {
      expect(advanced.contains(within(dialog).getByLabelText(label))).toBe(true);
    }

    fireEvent.change(within(dialog).getByLabelText('Tiers'), { target: { value: 'Carrefour' } });
    expect(advanced.querySelector('summary')?.textContent).toContain('Tiers');

    fireEvent.change(within(dialog).getByLabelText('Montant'), { target: { value: '-10.00' } });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'TEST' } });
    fireEvent.change(within(dialog).getByLabelText('Date de valeur'), {
      target: { value: '2020-01-01' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    expect(advanced.open).toBe(true);
    expect(
      within(dialog).getByText('La date de valeur doit rester à 90 jours de la date comptable.'),
    ).toBeTruthy();
    expect(api.createTransaction).not.toHaveBeenCalled();
  });

  it('keeps allocation beside the category when advanced metadata is closed', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Nouvelle transaction' });
    const advanced = within(dialog)
      .getByText('Champs avancés')
      .closest('details') as HTMLDetailsElement;
    const toggle = within(dialog).getByLabelText('Répartir entre plusieurs catégories');
    expect(advanced.contains(toggle)).toBe(false);

    fireEvent.click(toggle);

    expect(within(dialog).queryByRole('combobox', { name: 'Catégorie' })).toBeNull();
    expect(advanced.open).toBe(false);
    expect(within(dialog).getByRole('combobox', { name: 'Catégorie de la ligne 1' })).toBeTruthy();
  });

  it('filters on uncategorized transactions, requests the queue and shows its own empty state', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(({ query }) =>
      success({ items: query.categorization === 'NONE' ? [] : [transaction], nextCursor: null }),
    );
    renderPage();

    expect(await screen.findByText(/CB CARREFOUR/, { selector: 'button' })).toBeTruthy();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sans catégorie' }));
    expect(screen.getByRole('button', { name: 'Retirer le filtre Sans catégorie' })).toBeTruthy();

    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ categorization: 'NONE' }) }),
      ),
    );
    expect(await screen.findByRole('heading', { name: 'Rien à catégoriser' })).toBeTruthy();
  });

  it('names the signed amount and the voided state in words', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({
        items: [
          {
            ...transaction,
            state: 'VOIDED',
            voidedAt: '2026-03-15T10:00:00+01:00',
            rawLabel: '<img src=x onerror=alert(1)>',
            displayLabel: '<img src=x onerror=alert(1)>',
          },
        ],
        nextCursor: null,
      }),
    );
    renderPage();

    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    // Scoped to the list: the new filter bar also offers a "Voided" state checkbox with the
    // same label, so an unscoped query would match both.
    expect(
      within(screen.getByRole('list', { name: /^Transactions de l/ })).getByText('Annulée'),
    ).toBeTruthy();
    expect(screen.getByLabelText(/Sortie de/)).toBeTruthy();
  });

  it('badges only a state that departs from booked', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({
        items: [transaction, { ...transaction, id: 'pending', state: 'PENDING' }],
        nextCursor: null,
      }),
    );
    renderPage();

    await waitFor(() => expect(screen.queryByTestId('transaction-day-list-skeleton')).toBeNull());
    const table = screen.getByRole('list', { name: /^Transactions de l/ });
    expect(within(table).getByText('En attente')).toBeTruthy();
    // Booked is the normal case: the register states it nowhere.
    expect(within(table).queryByText('Comptabilisée')).toBeNull();
  });

  it('shows an explicit unauthorized state', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockResolvedValue({
      error: { status: 401 },
      response: new Response('{}', { status: 401 }),
    });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Session expirée' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('distinguishes a stale version from a terminal conflict', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.updateTransaction.mockResolvedValue({
      error: { type: '/problems/stale-version' },
      response: new Response('{}', { status: 409 }),
    });
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    fireEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Enregistrer' }),
    );

    expect(await screen.findByText(/modifiée ailleurs entre-temps/)).toBeTruthy();
  });

  it('asks for confirmation before voiding and keeps the confirm control unfocused', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.voidTransaction.mockImplementation(() =>
      success({
        ...transaction,
        state: 'VOIDED',
        voidedAt: '2026-03-15T10:00:00+01:00',
        version: 2,
      }),
    );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Annuler la transaction/ }));

    const dialog = screen.getByRole('dialog', { name: 'Annuler la transaction' });
    const confirm = within(dialog).getByRole('button', { name: 'Annuler la transaction' });
    expect(document.activeElement).not.toBe(confirm);
    fireEvent.click(confirm);

    await waitFor(() => expect(api.voidTransaction).toHaveBeenCalledOnce());
    expect(api.voidTransaction.mock.calls[0]?.[0]).toMatchObject({
      body: { version: 1 },
      path: { id: transaction.id },
    });
  });

  it('refreshes a stale void and retries with the current version', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions
      .mockImplementationOnce(() => success({ items: [transaction], nextCursor: null }))
      .mockImplementation(() =>
        success({ items: [{ ...transaction, version: 2 }], nextCursor: null }),
      );
    api.voidTransaction
      .mockResolvedValueOnce({
        error: { type: '/problems/stale-version' },
        response: new Response('{}', { status: 409 }),
      })
      .mockImplementation(({ body }) =>
        success({ ...transaction, state: 'VOIDED', version: body.version + 1 }),
      );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Annuler la transaction/ }));
    fireEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Annuler la transaction' }),
    );

    expect(await screen.findByText(/modifiée ailleurs entre-temps/)).toBeTruthy();
    await waitFor(() => expect(api.listTransactions).toHaveBeenCalledTimes(2));
    fireEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Annuler la transaction' }),
    );

    await waitFor(() => expect(api.voidTransaction).toHaveBeenCalledTimes(2));
    expect(api.voidTransaction.mock.calls[1]?.[0]).toMatchObject({ body: { version: 2 } });
  });

  it('duplicates from the row actions and reports a refused copy', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.duplicateTransaction.mockResolvedValue({
      error: { type: '/problems/transaction-conflict' },
      response: new Response('{}', { status: 409 }),
    });
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Dupliquer/ }));

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText(/ne peut plus être modifiée/)).toBeTruthy();
  });

  it('keeps duplication single-flight while the request is pending', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    let resolveDuplicate:
      ((value: Awaited<ReturnType<typeof success<Transaction>>>) => void) | undefined;
    api.duplicateTransaction.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveDuplicate = resolve;
        }),
    );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    const menu = screen.getByRole('button', {
      name: 'Actions de la transaction « CB CARREFOUR 1234 »',
    });
    fireEvent.click(menu);
    fireEvent.click(screen.getByRole('button', { name: /^Dupliquer/ }));
    await waitFor(() => expect(api.duplicateTransaction).toHaveBeenCalledOnce());
    fireEvent.click(menu);

    expect((screen.getByRole('button', { name: /^Dupliquer/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );

    await act(async () => {
      resolveDuplicate?.(await success(transaction, 201));
    });
  });

  it('shows and hydrates the assigned category when editing', async () => {
    const categorized = {
      ...transaction,
      splits: [
        {
          id: '00000000-0000-7000-8000-0000000000e1',
          categoryId: '00000000-0000-7000-8000-0000000000c1',
          categoryLabel: 'Courses',
          categoryIcon: 'basket',
          categoryColor: '#2E7D32',
          amount: transaction.amount,
          analyticAxes: [],
          note: null,
          categorizationOrigin: 'MANUAL',
          categorizationRuleId: null,
        },
      ],
    } satisfies Transaction;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [categorized], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    expect(await screen.findByText('Courses')).toBeTruthy();
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Courses',
    );
    await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Montant')));

    // Flipping the direction keeps the category and says what now contradicts it.
    fireEvent.change(screen.getByLabelText('Nature'), { target: { value: 'INCOME' } });
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Courses',
    );
    expect(
      screen.getByText('Catégorie de dépense : le montant doit être négatif, comme toute sortie.'),
    ).toBeTruthy();
  });

  it('restores the category axes of a saved transaction when its override is reset', async () => {
    const courses = {
      ...expenseCategory,
      defaultAnalyticAxes: ['ESSENTIAL', 'FIXED'],
    } as Category;
    const overridden = {
      ...transaction,
      splits: [
        {
          id: '00000000-0000-7000-8000-0000000000e1',
          categoryId: courses.id,
          categoryLabel: 'Courses',
          categoryIcon: null,
          categoryColor: null,
          amount: transaction.amount,
          analyticAxes: ['PERSONAL'],
          note: null,
          categorizationOrigin: 'MANUAL',
          categorizationRuleId: null,
        },
      ],
    } satisfies Transaction;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [overridden], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [courses], page: 1, pageSize: 50, total: 1 }),
    );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.click(
      within(dialog).getByRole('button', { name: 'Revenir aux axes de la catégorie' }),
    );

    const group = within(dialog).getByRole('group', { name: 'Axes analytiques de la transaction' });
    await waitFor(() =>
      expect((within(group).getByLabelText('Essentiel') as HTMLInputElement).checked).toBe(true),
    );
    expect((within(group).getByLabelText('Fixe') as HTMLInputElement).checked).toBe(true);
    expect((within(group).getByLabelText('Personnel') as HTMLInputElement).checked).toBe(false);
  });

  it('edits a manual label and explains the fields that stay locked', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.updateTransaction.mockImplementation(({ body }) => success({ ...transaction, ...body }));
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    const accountField = within(dialog).getByRole('combobox', {
      name: 'Compte',
    }) as HTMLSelectElement;
    const labelField = within(dialog).getByRole('textbox', {
      name: 'Libellé',
    }) as HTMLInputElement;
    expect(accountField.disabled).toBe(true);
    expect(labelField.readOnly).toBe(false);
    expect(within(dialog).getByText(/Pour changer de compte/)).toBeTruthy();
    expect(within(dialog).getByText(/ne revient pas en attente/)).toBeTruthy();

    fireEvent.change(labelField, { target: { value: 'CARREFOUR MARKET' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    expect(api.updateTransaction.mock.calls[0]?.[0].body).toMatchObject({
      rawLabel: 'CARREFOUR MARKET',
    });
  });

  it('edits the display label of an imported transaction and keeps the source label read-only', async () => {
    const imported = { ...transaction, source: 'IMPORT' } satisfies Transaction;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [imported], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.updateTransaction.mockImplementation(({ body }) => success({ ...imported, ...body }));
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    // The read-only source label is always there for an external row, so nothing pops in while typing.
    expect(within(dialog).getByRole('textbox', { name: 'Libellé d’origine' })).toBeTruthy();

    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Libellé affiché' }), {
      target: { value: 'Courses Carrefour' },
    });
    const source = within(dialog).getByRole('textbox', {
      name: 'Libellé d’origine',
    }) as HTMLInputElement;
    expect(source.readOnly).toBe(true);
    expect(source.value).toBe('CB CARREFOUR 1234');
    expect(within(dialog).getByText(/source externe/)).toBeTruthy();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
    expect(api.updateTransaction.mock.calls[0]?.[0].body).toMatchObject({
      rawLabel: 'CB CARREFOUR 1234',
      userLabel: 'Courses Carrefour',
    });
  });

  it('lists the display label of a transaction instead of its source label', async () => {
    const renamed = {
      ...transaction,
      source: 'IMPORT',
      userLabel: 'Courses Carrefour',
      displayLabel: 'Courses Carrefour',
    } satisfies Transaction;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [renamed], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    expect(await screen.findByText('Courses Carrefour')).toBeTruthy();
    expect(screen.queryByText('CB CARREFOUR 1234')).toBeNull();
  });

  it('resolves and edits a historical account beyond the first account page', async () => {
    const historicalAccount = {
      ...account,
      id: '00000000-0000-7000-8000-0000000000d9',
      label: 'Ancien compte',
      status: 'ARCHIVED',
    } as Account;
    const historicalTransaction = { ...transaction, accountId: historicalAccount.id };
    api.listAccounts.mockImplementation(({ query }) => {
      if (!query.includeArchived) {
        return success({ items: [account], page: 1, pageSize: 100, total: 1 });
      }
      return query.page === 1
        ? success({ items: [account], page: 1, pageSize: 100, total: 101 })
        : success({ items: [historicalAccount], page: 2, pageSize: 100, total: 101 });
    });
    api.listTransactions.mockImplementation(() =>
      success({ items: [historicalTransaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.updateTransaction.mockImplementation(({ body }) =>
      success({ ...historicalTransaction, ...body }),
    );
    renderPage();

    // Text first: a role query over the whole page is too slow to poll under a loaded run.
    expect((await screen.findAllByText('Ancien compte')).length).toBeGreaterThan(0);
    expect(screen.getByRole('checkbox', { name: 'Ancien compte' })).toBeTruthy();
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    fireEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Enregistrer' }),
    );

    await waitFor(() => expect(api.updateTransaction).toHaveBeenCalledOnce());
  });

  it('creates a same-asset transfer and shows a second amount field only for a cross-asset one', async () => {
    const savingsAccount = {
      ...account,
      id: '00000000-0000-7000-8000-0000000000d2',
      label: 'Épargne',
    } as Account;
    const chfAccount = {
      ...account,
      id: '00000000-0000-7000-8000-0000000000d3',
      label: 'Compte suisse',
      assetCode: 'CHF',
    } as Account;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, savingsAccount, chfAccount], page: 1, pageSize: 100, total: 3 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.createTransfer.mockImplementation(({ body }) =>
      success(
        {
          id: '00000000-0000-7000-8000-0000000000e1',
          source: {
            ...transaction,
            accountId: body.sourceAccountId,
            amount: { value: '-500.00', assetCode: 'EUR' },
          },
          target: {
            ...transaction,
            accountId: body.targetAccountId,
            amount: { value: '500.00', assetCode: 'EUR' },
          },
          fee: null,
          exchangeRate: null,
          version: 1,
          createdAt: '2026-03-14T09:12:04+01:00',
          updatedAt: '2026-03-14T09:12:04+01:00',
          voidedAt: null,
        },
        201,
      ),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Nouveau virement' }));
    const dialog = screen.getByRole('dialog', { name: 'Nouveau virement' });
    expect(within(dialog).queryByLabelText('Montant reçu')).toBeNull();

    fireEvent.change(within(dialog).getByLabelText('Compte source'), {
      target: { value: account.id },
    });
    fireEvent.change(within(dialog).getByLabelText('Compte de destination'), {
      target: { value: savingsAccount.id },
    });
    fireEvent.change(within(dialog).getByLabelText('Montant (EUR)'), {
      target: { value: '500.00' },
    });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: 'Virement épargne' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer le virement' }));

    await waitFor(() => expect(api.createTransfer).toHaveBeenCalledOnce());
    expect(api.createTransfer).toHaveBeenCalledWith(
      expect.objectContaining({
        body: expect.objectContaining({
          sourceAccountId: account.id,
          targetAccountId: savingsAccount.id,
          sourceAmount: { value: '500.00', assetCode: 'EUR' },
          targetAmount: null,
          fee: null,
        }),
      }),
    );
    expect(await screen.findByText('Le virement a été enregistré.')).toBeTruthy();

    // Reopening and pairing with the CHF account now asks for the target amount too.
    fireEvent.click(screen.getByRole('button', { name: 'Nouveau virement' }));
    const reopened = screen.getByRole('dialog', { name: 'Nouveau virement' });
    fireEvent.change(within(reopened).getByLabelText('Compte de destination'), {
      target: { value: chfAccount.id },
    });
    expect(within(reopened).getByLabelText('Montant reçu (CHF)')).toBeTruthy();
  });

  it('routes transfer edits and voids to the complete workflow and forbids standalone duplication', async () => {
    const transferLeg: Transaction = {
      ...transaction,
      nature: 'TRANSFER',
      transferId: '00000000-0000-7000-8000-0000000000e1',
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transferLeg], nextCursor: null }),
    );
    renderPage();

    expect(await screen.findByText('Virement')).toBeTruthy();
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    expect((screen.getByRole('button', { name: /^Modifier/ }) as HTMLButtonElement).disabled).toBe(
      false,
    );
    expect((screen.getByRole('button', { name: /^Dupliquer/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect((screen.getByRole('button', { name: /^Annuler/ }) as HTMLButtonElement).disabled).toBe(
      false,
    );
  });

  it('invalidates all balance and report queries after saving a refund', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.getTransactionRefundable.mockImplementation(() =>
      success({
        originalId: transaction.id,
        originalAmount: transaction.amount,
        refunded: { value: '0', assetCode: 'EUR' },
        refundable: { value: '42.90', assetCode: 'EUR' },
        proposedSplits: [],
      }),
    );
    api.createTransactionRefund.mockImplementation(() =>
      success({ ...transaction, nature: 'REFUND' }),
    );
    const { queryClient } = renderPage();
    const keys = [
      'accounts',
      'account',
      'account-rules',
      'net-worth',
      'net-worth-history',
      'monthly-recap',
      'annual-report',
      'multi-year-report',
    ];
    keys.forEach((key) => queryClient.setQueryData([key, 'cached'], {}));
    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Créer un remboursement/ }));
    await screen.findByLabelText('Montant');
    fireEvent.change(screen.getByLabelText('Date comptable'), { target: { value: '2026-03-14' } });
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Remboursement' } });
    fireEvent.click(screen.getByRole('button', { name: 'Créer le remboursement' }));
    await waitFor(() => expect(api.createTransactionRefund).toHaveBeenCalledOnce());
    await waitFor(() => {
      for (const key of keys)
        expect(queryClient.getQueryState([key, 'cached'])?.isInvalidated, key).toBe(true);
    });
  });

  it('disables refunding an expense already refunded for its full amount', async () => {
    const settled: Transaction = {
      ...transaction,
      refundedAmount: { value: '42.90', assetCode: 'EUR' },
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [settled], nextCursor: null }));
    renderPage();

    expect(await screen.findByText('Remboursé : 42,90 €')).toBeTruthy();
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    expect(
      (screen.getByRole('button', { name: /^Créer un remboursement/ }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
  });

  it('keeps the URL in sync with a filter change wired through the page', async () => {
    window.history.replaceState({}, '', '/transactions');
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();

    await screen.findByRole('heading', { name: 'Aucune transaction' });
    fireEvent.click(
      [...document.querySelectorAll('summary')].find((summary) => summary.textContent === 'État')!,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: 'Comptabilisée' }));

    await waitFor(() =>
      expect(new URLSearchParams(window.location.search).getAll('state')).toEqual(['BOOKED']),
    );
    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenLastCalledWith(
        expect.objectContaining({
          query: expect.objectContaining({ state: ['BOOKED'] }),
        }),
      ),
    );

    window.history.replaceState({}, '', '/transactions');
  });

  it('groups the loaded page by booking day with a weekday and date heading', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({
        items: [{ ...transaction, id: 'first', bookedOn: '2026-03-15' }, transaction],
        nextCursor: null,
      }),
    );
    renderPage();

    expect(await screen.findByRole('heading', { name: /dimanche 15 mars 2026/ })).toBeTruthy();
    expect(screen.getByRole('heading', { name: /samedi 14 mars 2026/ })).toBeTruthy();
    expect(screen.queryByText(/Total du jour|Jour incomplet/)).toBeNull();
  });

  it('keeps the loaded rows and offers a reload when a page request finds the cursor stale', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.listTransactions
      .mockImplementationOnce(() => success({ items: [transaction], nextCursor: 'cursor-1' }))
      .mockImplementationOnce(() =>
        Promise.resolve({
          data: undefined,
          error: { type: '/problems/transactions.cursor_stale', title: 'Stale', status: 409 },
          response: new Response(null, { status: 409 }),
        }),
      );
    renderPage();

    expect(await screen.findByText('CB CARREFOUR 1234')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus' }));

    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      expect.stringContaining('La liste a changé pendant le chargement de cette page'),
    );
    // The rows already loaded stay on screen instead of being blanked out.
    expect(screen.getByText('CB CARREFOUR 1234')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Recharger depuis le début' }));
    await waitFor(() => expect(api.listTransactions).toHaveBeenCalledTimes(3));
  });

  it('reloads from the first page after saving an edit made on a later page', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    const edited = {
      ...transaction,
      rawLabel: 'CARREFOUR MARKET',
      displayLabel: 'CARREFOUR MARKET',
      version: 2,
    };
    let saved = false;
    api.listTransactions.mockImplementation(({ query }: { query: { cursor?: string } }) => {
      if (query.cursor) {
        return saved
          ? Promise.resolve({
              data: undefined,
              error: { type: '/problems/transactions.cursor_stale', title: 'Stale', status: 409 },
              response: new Response(null, { status: 409 }),
            })
          : success({ items: [transaction], nextCursor: null });
      }
      return success({ items: [saved ? edited : transaction], nextCursor: 'cursor-1' });
    });
    api.updateTransaction.mockImplementation(() => {
      saved = true;
      return success(edited);
    });
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus' }));
    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ cursor: 'cursor-1' }) }),
      ),
    );
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Afficher plus' })).toBeNull());

    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Libellé' }), {
      target: { value: 'CARREFOUR MARKET' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    expect(await screen.findByText('CARREFOUR MARKET')).toBeTruthy();
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ cursor: undefined }) }),
    );
    expect(screen.queryByText(/La liste a changé pendant le chargement/)).toBeNull();
  });

  it('changes the announced row count between two pages that load the same count', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    const secondPageTransaction: Transaction = {
      ...transaction,
      id: '00000000-0000-7000-8000-0000000000f2',
      rawLabel: 'CB FNAC 5678',
      displayLabel: 'CB FNAC 5678',
      counterparty: 'Fnac',
    };
    api.listTransactions
      .mockImplementationOnce(() => success({ items: [transaction], nextCursor: 'cursor-1' }))
      .mockImplementationOnce(() => success({ items: [secondPageTransaction], nextCursor: null }));
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    const firstAnnouncement = screen.getByRole('status').textContent;

    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus' }));

    await screen.findByText('CB FNAC 5678');
    await waitFor(() => expect(screen.getByRole('status').textContent).not.toBe(firstAnnouncement));
  });
});

describe('primary account creation defaults', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/transactions');
  });

  it.each([false, true])(
    'uses primary=%s without falling back to the first account',
    async (primary) => {
      const other = {
        ...account,
        id: '00000000-0000-7000-8000-0000000000d2',
        isPrimary: primary,
        label: 'Principal',
      };
      api.listAccounts.mockImplementation(() =>
        success({
          items: [{ ...account, isPrimary: false }, other],
          page: 1,
          pageSize: 100,
          total: 2,
        }),
      );
      api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
      api.listCategories.mockImplementation(() =>
        success({ items: [], page: 1, pageSize: 50, total: 0 }),
      );
      renderPage();
      fireEvent.click(
        await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
      );
      const select = within(screen.getByRole('dialog')).getByRole('combobox', {
        name: 'Compte',
      }) as HTMLSelectElement;
      expect(select.value).toBe(primary ? other.id : '');
      fireEvent.change(select, { target: { value: account.id } });
      expect(select.value).toBe(account.id);
      fireEvent.change(select, { target: { value: '' } });
      expect(select.value).toBe('');
    },
  );

  it('prefers the filtered active account over the primary', async () => {
    const other = {
      ...account,
      id: '00000000-0000-7000-8000-0000000000d2',
      isPrimary: false,
      label: 'Filtré',
    };
    window.history.replaceState({}, '', `/transactions?accountId=${other.id}`);
    api.listAccounts.mockImplementation(() =>
      success({ items: [account, other], page: 1, pageSize: 100, total: 2 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Nouvelle transaction' }));
    expect(
      (
        within(screen.getByRole('dialog')).getByRole('combobox', {
          name: 'Compte',
        }) as HTMLSelectElement
      ).value,
    ).toBe(other.id);
  });

  it('shows no selection chrome until the bulk edit mode is chosen, and clears on leaving', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    expect(screen.queryByRole('checkbox', { name: /^Sélectionner/ })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Sélectionner' }));
    fireEvent.click(
      await screen.findByRole('checkbox', { name: 'Sélectionner CB CARREFOUR 1234' }),
    );
    expect(screen.getByText('1 mouvement sélectionné')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Modifier' }) as HTMLButtonElement).disabled).toBe(
      false,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Quitter la sélection' }));
    expect(screen.queryByRole('checkbox', { name: /^Sélectionner/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Sélectionner' }));
    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('clears the bulk selection when a filter changes', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    renderPage();
    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(screen.getByRole('button', { name: 'Sélectionner' }));
    fireEvent.click(
      await screen.findByRole('checkbox', { name: 'Sélectionner CB CARREFOUR 1234' }),
    );

    fireEvent.click(
      [...document.querySelectorAll('summary')].find((summary) => summary.textContent === 'État')!,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: 'Comptabilisée' }));

    await waitFor(() => expect(screen.getByText('Aucune sélection')).toBeTruthy());
    window.history.replaceState({}, '', '/transactions');
  });
});

describe('TransactionsPage option counts', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/transactions');
  });

  const facets: TransactionFacets = {
    accountId: { [account.id]: 184 },
    categoryId: {},
    nature: { EXPENSE: 150, INCOME: 34 },
    state: { BOOKED: 184, PENDING: 3 },
    source: { MANUAL: 12, IMPORT: 172 },
    axis: {},
  };

  function loadPage() {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, pageSize: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 50, total: 0 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
  }

  function section(title: string) {
    return [...document.querySelectorAll('summary')]
      .find((summary) => summary.textContent?.startsWith(title))!
      .closest('details')!;
  }

  function openSection(title: string) {
    fireEvent.click(section(title).querySelector('summary')!);
  }

  it('asks for the counts under the filters of the list, without its paging, and reads them in the options', async () => {
    window.history.replaceState({}, '', '/transactions?direction=OUTFLOW');
    loadPage();
    api.readTransactionFacets.mockImplementation(() => success(facets));
    renderPage();
    await screen.findByText(transaction.rawLabel);
    openSection('État');

    expect(
      await screen.findByRole('checkbox', { name: 'Comptabilisée, 184 transactions' }),
    ).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'En attente, 3 transactions' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Annulée, 0 transaction' })).toBeTruthy();
    const listQuery = api.listTransactions.mock.calls[0]?.[0].query;
    const facetsQuery = api.readTransactionFacets.mock.calls[0]?.[0].query;
    expect(facetsQuery).toEqual({ direction: 'OUTFLOW' });
    expect(listQuery).toEqual({ ...facetsQuery, cursor: undefined, pageSize: 50 });
  });

  it('counts again once an option is checked, the shown counts staying until the new ones arrive', async () => {
    loadPage();
    let resolveSecond: (value: unknown) => void = () => {};
    api.readTransactionFacets
      .mockImplementationOnce(() => success(facets))
      .mockImplementationOnce(() => new Promise((done) => (resolveSecond = done)));
    renderPage();
    await screen.findByText(transaction.rawLabel);
    openSection('État');
    fireEvent.click(
      await screen.findByRole('checkbox', { name: 'Comptabilisée, 184 transactions' }),
    );

    // The counts follow the debounced filters, a quarter of a second after the list.
    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledTimes(2), {
      timeout: 3000,
    });
    expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual({ state: ['BOOKED'] });
    expect(api.listTransactions).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ state: ['BOOKED'] }) }),
    );
    // Siblings are still counted while the new answer loads, with no skeleton flash.
    expect(screen.getByRole('checkbox', { name: 'En attente, 3 transactions' })).toBeTruthy();
    expect(section('État').querySelectorAll('[data-skeleton]')).toHaveLength(0);

    resolveSecond(await success({ ...facets, state: { BOOKED: 184, PENDING: 0 } }));
    expect(await screen.findByRole('checkbox', { name: 'En attente, 0 transaction' })).toBeTruthy();
  });

  it('keeps the filters and the list working, with no count at all, when the counts fail', async () => {
    loadPage();
    renderPage();
    await screen.findByText(transaction.rawLabel);
    openSection('État');
    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());

    const booked = await screen.findByRole('checkbox', { name: 'Comptabilisée' });
    expect(section('État').querySelectorAll('[data-skeleton]')).toHaveLength(0);
    expect(section('État').textContent).not.toMatch(/\d/);
    fireEvent.click(booked);

    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ state: ['BOOKED'] }) }),
      ),
    );
    expect(await screen.findByText(transaction.rawLabel)).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée' })).toHaveProperty('checked', true);
  });

  it('shows a skeleton count on every option while the first answer is pending, the options staying usable', async () => {
    loadPage();
    api.readTransactionFacets.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    await screen.findByText(transaction.rawLabel);
    openSection('État');

    expect(section('État').querySelectorAll('[data-skeleton]')).toHaveLength(4);
    const booked = screen.getByRole('checkbox', { name: 'Comptabilisée' });
    expect(booked).toHaveProperty('disabled', false);
    fireEvent.click(booked);

    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ state: ['BOOKED'] }) }),
      ),
    );
  });

  it('draws skeleton counts in the frozen preview a page reload shows, and requests nothing', () => {
    render(
      <FrozenQueries>
        <TransactionsPage />
      </FrozenQueries>,
    );
    openSection('État');

    expect(section('État').querySelectorAll('[data-skeleton]')).toHaveLength(4);
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée' })).toBeTruthy();
    expect(api.readTransactionFacets).not.toHaveBeenCalled();
  });

  /** Long enough for the debounce of a changed request to have fired, were one to be sent. */
  function waitPastDebounce() {
    return new Promise((done) => setTimeout(done, 400));
  }

  it('asks for no count while the filter column is hidden, whatever the page does, and asks once it is shown', async () => {
    window.localStorage.setItem('cadran.transactions.filters-hidden', '1');
    window.history.replaceState({}, '', '/transactions?period=month&month=2026-11');
    loadPage();
    api.readTransactionFacets.mockImplementation(() => success(facets));
    renderPage();
    await screen.findByText(transaction.rawLabel);

    // A page load used to cost a count nobody could see.
    await waitPastDebounce();
    expect(api.readTransactionFacets).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /^Filtres/ }));
    openSection('État');
    expect(
      await screen.findByRole('checkbox', { name: 'Comptabilisée, 184 transactions' }),
    ).toBeTruthy();
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
    expect(api.readTransactionFacets.mock.calls[0]?.[0].query).toEqual({
      from: '2026-11-01',
      to: '2026-11-30',
    });
  });

  it('asks for no count on a narrow screen until the filter sheet opens', async () => {
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: true,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }));
    loadPage();
    api.readTransactionFacets.mockImplementation(() => success(facets));
    renderPage();
    await screen.findByText(transaction.rawLabel);
    await waitPastDebounce();
    expect(api.readTransactionFacets).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /^Filtres/ }));
    const sheet = screen.getByRole('dialog', { name: 'Filtres' });
    openSection('État');

    expect(
      await within(sheet).findByRole('checkbox', { name: 'Comptabilisée, 184 transactions' }),
    ).toBeTruthy();
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
  });

  it('asks for no count while the filters cannot match anything', async () => {
    window.history.replaceState({}, '', '/transactions?categorization=NONE&axis=FIXED');
    loadPage();
    api.readTransactionFacets.mockImplementation(() => success(facets));
    renderPage();

    expect(
      await screen.findByRole('heading', {
        name: 'Cette combinaison de filtres ne peut rien trouver',
      }),
    ).toBeTruthy();
    openSection('État');

    expect(api.readTransactionFacets).not.toHaveBeenCalled();
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée' })).toBeTruthy();
    expect(section('État').querySelectorAll('[data-skeleton]')).toHaveLength(0);
  });

  it('counts again after a write, as the list loads again', async () => {
    loadPage();
    api.readTransactionFacets.mockImplementation(() => success(facets));
    api.updateTransaction.mockImplementation(() =>
      success({
        ...transaction,
        rawLabel: 'CARREFOUR MARKET',
        displayLabel: 'CARREFOUR MARKET',
        version: 2,
      }),
    );
    renderPage();
    await screen.findByText('CB CARREFOUR 1234');
    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());

    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Libellé' }), {
      target: { value: 'CARREFOUR MARKET' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledTimes(2));
    expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual({});
  });
});
