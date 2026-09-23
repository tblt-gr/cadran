import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyBudgetPage } from './MonthlyBudgetPage';

const api = vi.hoisted(() => ({
  createTransaction: vi.fn(),
  createTransfer: vi.fn(),
  listAccounts: vi.fn(),
  readMonthlyLedger: vi.fn(),
  readMonthlyLedgerMovements: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const incomeId = '00000000-0000-7000-8000-000000000001';
const expenseId = '00000000-0000-7000-8000-000000000002';
const accountId = '00000000-0000-7000-8000-000000000003';
const ledger = {
  month: '2026-03',
  periodStart: '2026-03-01',
  periodEnd: '2026-03-31',
  axis: null,
  state: 'COMPLETE' as const,
  quality: 'CURRENT' as const,
  timezone: 'Europe/Paris',
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

  it('renders exact zero separately from an empty active row and marks out-of-budget expenses', async () => {
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Budget de mars 2026' })).toBeTruthy();
    expect(await screen.findByText('Salaire')).toBeTruthy();
    expect(
      screen.getAllByText(
        (_content, node) => node?.textContent === '0 €' && node.tagName === 'SPAN',
      ).length,
    ).toBeGreaterThan(0);
    expect(screen.getByText('Aucun mouvement')).toBeTruthy();
    expect(screen.getByText('Hors budget')).toBeTruthy();
    expect(screen.getByText('2 transactions en attente')).toBeTruthy();
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
