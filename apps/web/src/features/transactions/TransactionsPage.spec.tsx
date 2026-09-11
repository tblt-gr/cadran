import type { Account, Category, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionsPage } from './TransactionsPage';

const api = vi.hoisted(() => ({
  createCategory: vi.fn(),
  createTransaction: vi.fn(),
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

  it('offers expense categories before an amount is entered', async () => {
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listTransactions.mockImplementation(() => success({ items: [], nextCursor: null }));
    api.listCategories.mockImplementation(({ query }) =>
      success({
        items: query.type === 'EXPENSE' ? [expenseCategory] : [],
        page: 1,
        perPage: 50,
        total: query.type === 'EXPENSE' ? 1 : 0,
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole('button', { name: 'Enregistrer la première transaction' }),
    );
    fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));

    expect(await screen.findByRole('option', { name: 'Courses' })).toBeTruthy();
    expect(api.listCategories).toHaveBeenCalledWith(
      expect.objectContaining({
        query: expect.objectContaining({ type: 'EXPENSE' }),
      }),
    );

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'Catégorie' }), { key: 'Escape' });
    expect(screen.getByRole('dialog', { name: 'Nouvelle transaction' })).toBeTruthy();
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

  it('creates a category of the other type without selecting it in the draft', async () => {
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
        '« Prime » est créée avec le type Revenu, qui ne correspond pas au signe de ce mouvement : elle n’a pas été sélectionnée.',
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
    expect(screen.getByText('Annulée')).toBeTruthy();
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

    fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '42.90' } });
    expect((screen.getByRole('combobox', { name: 'Catégorie' }) as HTMLInputElement).value).toBe(
      '',
    );
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
});
