import type { Account, Category, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionsPage } from './TransactionsPage';

const api = vi.hoisted(() => ({
  createCategory: vi.fn(),
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  duplicateTransaction: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  listTransactions: vi.fn(),
  updateTransaction: vi.fn(),
  voidTransaction: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Compte courant',
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
  bookedOn: '2026-03-14',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CB CARREFOUR 1234',
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
};

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <TransactionsPage />
    </QueryClientProvider>,
  );
}

describe('TransactionsPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('offers every category, expenses first, whatever the nature or the sign', async () => {
    const incomeCategory = {
      ...expenseCategory,
      id: '00000000-0000-7000-8000-0000000000c2',
      type: 'INCOME',
      label: 'Salaire',
    } as Category;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    // The server lists income first here: the picker still leads with what the sign expects.
    api.listCategories.mockImplementation(() =>
      success({ items: [incomeCategory, expenseCategory], page: 1, perPage: 50, total: 2 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [expenseCategory, incomeCategory], page: 1, perPage: 50, total: 2 }),
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

    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '1500.00' } });
    expect(screen.queryByText(message)).toBeNull();
    expect((picker as HTMLInputElement).value).toBe('Salaire');
  });

  it('quick-creates and selects a category without changing the transaction draft', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('keeps quick-create errors inside the nested dialog and leaves the draft untouched', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('cancels quick creation without touching the draft or the transaction focus trap', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('prefills the income type for a positive amount', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('closes the advanced fields with the split, which takes over the category slot', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
    const fields = within(advanced).getAllByRole('checkbox');
    expect(fields.at(-1)).toBe(toggle);
    expect(
      within(advanced).getByLabelText('Note').compareDocumentPosition(toggle) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();

    fireEvent.click(toggle);

    expect(within(dialog).queryByRole('combobox', { name: 'Catégorie' })).toBeNull();
    expect(
      within(dialog).getByText(
        'Répartie entre plusieurs catégories : voir la répartition en bas des champs avancés.',
      ),
    ).toBeTruthy();
    expect(advanced.querySelector('summary')?.textContent).toContain('Répartition');
    expect(
      within(advanced).getByRole('combobox', { name: 'Catégorie de la ligne 1' }),
    ).toBeTruthy();
  });

  it('switches to the to-categorise queue, requests it and shows its own empty state', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(({ query }) =>
      success({ items: query.categorization === 'NONE' ? [] : [transaction], nextCursor: null }),
    );
    renderPage();

    expect(await screen.findByRole('row', { name: /CB CARREFOUR/ })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'À catégoriser' }));

    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ categorization: 'NONE' }) }),
      ),
    );
    expect(await screen.findByRole('heading', { name: 'Rien à catégoriser' })).toBeTruthy();
  });

  it('names the signed amount and the voided state in words', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({
        items: [
          {
            ...transaction,
            state: 'VOIDED',
            voidedAt: '2026-03-15T10:00:00+01:00',
            rawLabel: '<img src=x onerror=alert(1)>',
          },
        ],
        nextCursor: null,
      }),
    );
    renderPage();

    expect(await screen.findByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    // Scoped to the table: the new filter bar also offers a "Voided" state checkbox with the
    // same label, so an unscoped query would match both.
    expect(within(screen.getByRole('table')).getByText('Annulée')).toBeTruthy();
    expect(screen.getByLabelText(/Sortie de/)).toBeTruthy();
  });

  it('shows an explicit unauthorized state', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [categorized], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

    // Flipping the sign keeps the category and says what now contradicts it.
    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '42.90' } });
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      'Courses',
    );
    expect(
      screen.getByText('Catégorie de dépense : le montant doit être négatif, comme toute sortie.'),
    ).toBeTruthy();
  });

  it('edits a manual label and explains the fields that stay locked', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() =>
      success({ items: [transaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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

  it('keeps an imported source label read-only and explains why', async () => {
    const imported = { ...transaction, source: 'IMPORT' } satisfies Transaction;
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [imported], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la transaction « CB CARREFOUR 1234 »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));

    const dialog = screen.getByRole('dialog', { name: 'Modifier la transaction' });
    expect(
      (
        within(dialog).getByRole('textbox', {
          name: 'Libellé d’origine',
        }) as HTMLInputElement
      ).readOnly,
    ).toBe(true);
    expect(within(dialog).getByText(/source externe/)).toBeTruthy();
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
        return success({ items: [account], page: 1, perPage: 100, total: 1 });
      }
      return query.page === 1
        ? success({ items: [account], page: 1, perPage: 100, total: 101 })
        : success({ items: [historicalAccount], page: 2, perPage: 100, total: 101 });
    });
    api.listTransactions.mockImplementation(() =>
      success({ items: [historicalTransaction], nextCursor: null }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    api.updateTransaction.mockImplementation(({ body }) =>
      success({ ...historicalTransaction, ...body }),
    );
    renderPage();

    expect(await screen.findByRole('cell', { name: 'Ancien compte' })).toBeTruthy();
    fireEvent.focus(screen.getByRole('combobox', { name: 'Filtrer par compte' }));
    expect(screen.getByRole('option', { name: 'Ancien compte' })).toBeTruthy();
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
      success({ items: [account, savingsAccount, chfAccount], page: 1, perPage: 100, total: 3 }),
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

  it('marks a transfer leg in the list and disables editing, duplicating and voiding it directly', async () => {
    const transferLeg: Transaction = {
      ...transaction,
      nature: 'TRANSFER',
      transferId: '00000000-0000-7000-8000-0000000000e1',
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      true,
    );
    expect((screen.getByRole('button', { name: /^Dupliquer/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect((screen.getByRole('button', { name: /^Annuler/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('disables refunding an expense already refunded for its full amount', async () => {
    const settled: Transaction = {
      ...transaction,
      refundedAmount: { value: '42.90', assetCode: 'EUR' },
    };
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderPage();

    await screen.findByRole('heading', { name: 'Aucune transaction' });
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

  it('keeps the loaded rows and offers a reload when a page request finds the cursor stale', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
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
    fireEvent.click(screen.getByRole('button', { name: 'Mouvements plus anciens' }));

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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    const edited = { ...transaction, rawLabel: 'CARREFOUR MARKET', version: 2 };
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
    fireEvent.click(screen.getByRole('button', { name: 'Mouvements plus anciens' }));
    await waitFor(() =>
      expect(api.listTransactions).toHaveBeenLastCalledWith(
        expect.objectContaining({ query: expect.objectContaining({ cursor: 'cursor-1' }) }),
      ),
    );
    await waitFor(() =>
      expect(screen.queryByRole('button', { name: 'Mouvements plus anciens' })).toBeNull(),
    );

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
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    const secondPageTransaction: Transaction = {
      ...transaction,
      id: '00000000-0000-7000-8000-0000000000f2',
      rawLabel: 'CB FNAC 5678',
      counterparty: 'Fnac',
    };
    api.listTransactions
      .mockImplementationOnce(() => success({ items: [transaction], nextCursor: 'cursor-1' }))
      .mockImplementationOnce(() => success({ items: [secondPageTransaction], nextCursor: null }));
    renderPage();

    await screen.findByText('CB CARREFOUR 1234');
    const firstAnnouncement = screen.getByRole('status').textContent;

    fireEvent.click(screen.getByRole('button', { name: 'Mouvements plus anciens' }));

    await screen.findByText('CB FNAC 5678');
    await waitFor(() => expect(screen.getByRole('status').textContent).not.toBe(firstAnnouncement));
  });
});
