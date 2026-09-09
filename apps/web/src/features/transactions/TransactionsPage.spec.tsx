import type { Account, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionsPage } from './TransactionsPage';

const api = vi.hoisted(() => ({
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
    fireEvent.change(screen.getByLabelText('Libellé d’origine'), {
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
          amount: transaction.amount,
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
