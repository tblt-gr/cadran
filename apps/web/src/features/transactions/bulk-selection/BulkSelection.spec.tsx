import type { Category, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';
import { BulkCategoryResult } from './bulk-category-modal/BulkCategoryResult';
import { BulkSelectionToolbar } from './BulkSelectionToolbar';
import { useBulkSelection } from './useBulkSelection';

const api = vi.hoisted(() => ({
  bulkCategorizeTransactions: vi.fn(),
  countTransactions: vi.fn(),
  getSession: vi.fn(),
  listCategories: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const FIRST = '00000000-0000-7000-8000-0000000000f1';
const SECOND = '00000000-0000-7000-8000-0000000000f2';
const CATEGORY = '00000000-0000-7000-8000-0000000000c1';

function tx(id: string, label: string): Transaction {
  return {
    id,
    accountId: 'acc-1',
    bookedOn: '2026-09-11',
    amount: { value: '-10.00', assetCode: 'EUR' },
    nature: 'EXPENSE',
    state: 'BOOKED',
    rawLabel: label,
    displayLabel: label,
    splits: [],
    transferId: null,
    refundOriginalLabel: null,
    refundedAmount: null,
    reviewReason: null,
  } as unknown as Transaction;
}

const category = {
  id: CATEGORY,
  type: 'EXPENSE',
  label: 'Courses',
  archivedAt: null,
  effectiveColor: null,
  effectiveIcon: null,
} as unknown as Category;

function ok<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function problem(status: number, body: Record<string, unknown>) {
  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

const reloadAfterWrite = vi.fn(() => Promise.resolve());

function Harness({ hasMore = false, resetKey = 'a' }: { hasMore?: boolean; resetKey?: string }) {
  const items = [tx(FIRST, 'Carrefour'), tx(SECOND, 'Monoprix')];
  const [active, setActive] = useState(true);
  const bulk = useBulkSelection({
    hasMore,
    items,
    query: { q: 'market' },
    reloadAfterWrite,
    resetKey: `${resetKey}:${active}`,
  });

  return (
    <>
      <BulkSelectionToolbar active={active} bulk={bulk} onToggleActive={() => setActive(!active)} />
      <TransactionDayList selection={active ? bulk.rowSelection : undefined} transactions={items} />
    </>
  );
}

function renderHarness(props: Parameters<typeof Harness>[0] = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const view = (next: Parameters<typeof Harness>[0]) => (
    <QueryClientProvider client={queryClient}>
      <Harness {...next} />
    </QueryClientProvider>
  );
  const rendered = render(view(props));

  return {
    ...rendered,
    update: (next: Parameters<typeof Harness>[0]) => rendered.rerender(view(next)),
  };
}

async function chooseCategory() {
  fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));
  fireEvent.mouseDown(await screen.findByRole('option', { name: 'Courses' }));
}

describe('bulk selection', () => {
  beforeEach(() => {
    api.listCategories.mockImplementation(() =>
      ok({ items: [category], page: 1, pageSize: 50, total: 1 }),
    );
    api.countTransactions.mockImplementation(() => ok({ count: 120 }));
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('offers a labelled checkbox per row and a select-all with a counter', () => {
    renderHarness();

    expect(screen.getByText('Aucune sélection')).toBeTruthy();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));
    expect(screen.getByText('1 mouvement sélectionné')).toBeTruthy();

    expect(screen.getByText('1 mouvement sélectionné')).toBeTruthy();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    expect(screen.getByText('Aucune sélection')).toBeTruthy();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    expect(screen.getByText('2 mouvements sélectionnés')).toBeTruthy();
    expect(
      (screen.getByRole('checkbox', { name: 'Sélectionner Monoprix' }) as HTMLInputElement).checked,
    ).toBe(true);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('leaves the mode with the pen, dropping checkboxes and the selection', () => {
    renderHarness();
    const pen = screen.getByRole('button', { name: 'Édition groupée' });
    expect(pen.getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));

    fireEvent.click(pen);
    expect(pen.getAttribute('aria-pressed')).toBe('false');
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Changer la catégorie' })).toBeNull();

    fireEvent.click(pen);
    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('clears the selection when the result set changes', () => {
    const { update } = renderHarness();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));

    update({ resetKey: 'b' });

    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('keeps the category action disabled until something is selected', () => {
    renderHarness();
    const action = screen.getByRole('button', { name: 'Changer la catégorie' });
    expect((action as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));
    expect((action as HTMLButtonElement).disabled).toBe(false);
  });

  it('offers every transaction matching the filters with the exact count', async () => {
    renderHarness({ hasMore: true });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));

    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Sélectionner les 120 mouvements correspondant aux filtres',
      }),
    );

    expect(api.countTransactions).toHaveBeenCalledWith(
      expect.objectContaining({ query: { q: 'market' } }),
    );
    expect(
      screen.getByText('Les 120 mouvements correspondant aux filtres sont sélectionnés'),
    ).toBeTruthy();
  });

  it('applies a category to the selected ids and reports the skipped ones', async () => {
    api.bulkCategorizeTransactions.mockImplementation(() =>
      ok({
        requestedCount: 2,
        changed: 1,
        maximum: 500,
        skipped: [{ transactionId: SECOND, reason: 'CLOSED_PERIOD' }],
      }),
    );
    renderHarness();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    fireEvent.click(screen.getByRole('button', { name: 'Changer la catégorie' }));

    const dialog = screen.getByRole('dialog', { name: 'Changer la catégorie' });
    const confirm = within(dialog).getByRole('button', { name: 'Confirmer' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
    await chooseCategory();
    fireEvent.click(confirm);

    await waitFor(() => expect(within(dialog).getByText('1 mouvement modifié')).toBeTruthy());
    expect(api.bulkCategorizeTransactions).toHaveBeenCalledWith(
      expect.objectContaining({
        body: { categoryId: CATEGORY, scope: { type: 'IDS', ids: [FIRST, SECOND] } },
        headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
      }),
    );
    expect(within(dialog).getByText('1 mouvement ignoré')).toBeTruthy();
    expect(within(dialog).getByText(/période clôturée/i)).toBeTruthy();
    expect(reloadAfterWrite).toHaveBeenCalled();
    const details = within(dialog)
      .getByText(/période clôturée/i)
      .closest('details');
    expect(details?.open).toBe(false);
    expect(within(details as HTMLElement).getByText(/^Monoprix, /)).toBeTruthy();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Terminer' }));
  });

  it('asks to narrow the filters when the scope exceeds the limit', async () => {
    api.countTransactions.mockImplementation(() => ok({ count: 800 }));
    api.bulkCategorizeTransactions.mockImplementation(() =>
      problem(422, {
        type: '/problems/transactions.bulk-scope-too-large',
        status: 422,
        limit: 500,
      }),
    );
    renderHarness({ hasMore: true });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Sélectionner les 800 mouvements correspondant aux filtres',
      }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Changer la catégorie' }));
    await chooseCategory();
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));

    expect(await screen.findByText(/Affinez vos filtres/)).toBeTruthy();
    expect(api.bulkCategorizeTransactions).toHaveBeenCalledWith(
      expect.objectContaining({
        body: expect.objectContaining({
          scope: { type: 'FILTER', filters: { q: 'market' }, expectedCount: 800 },
        }),
      }),
    );
  });

  it('asks to confirm again with the new count when the matching set changed', async () => {
    api.bulkCategorizeTransactions
      .mockImplementationOnce(() =>
        problem(409, {
          type: '/problems/transactions.bulk-scope-changed',
          status: 409,
          actualCount: 125,
        }),
      )
      .mockImplementationOnce(() =>
        ok({ requestedCount: 125, changed: 125, maximum: 500, skipped: [] }),
      );
    renderHarness({ hasMore: true });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Sélectionner les 120 mouvements correspondant aux filtres',
      }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Changer la catégorie' }));
    await chooseCategory();
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));

    expect(await screen.findByText(/Le nombre de mouvements a changé : 125/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));

    await waitFor(() => expect(api.bulkCategorizeTransactions).toHaveBeenCalledTimes(2));
    expect(api.bulkCategorizeTransactions.mock.calls[1]?.[0].body.scope.expectedCount).toBe(125);
  });
});

describe('bulk result', () => {
  afterEach(cleanup);

  it('names a skipped transaction missing from the loaded page generically', () => {
    render(
      <BulkCategoryResult
        onDone={vi.fn()}
        pageItems={[]}
        result={{
          requestedCount: 1,
          changed: 0,
          maximum: 500,
          skipped: [{ transactionId: FIRST, reason: 'SPLIT' }],
        }}
      />,
    );

    expect(screen.getByText('Mouvement hors de la page chargée')).toBeTruthy();
  });
});
