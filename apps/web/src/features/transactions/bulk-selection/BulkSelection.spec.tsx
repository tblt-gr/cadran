import type { Category, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionDayList } from '@/features/transactions/transaction-list/transaction-day-list/TransactionDayList';
import { BulkEditResultView } from './bulk-edit-modal/BulkEditResult';
import { BulkSelectionPen, BulkSelectionToolbar } from './BulkSelectionToolbar';
import { useBulkSelection } from './useBulkSelection';

const api = vi.hoisted(() => ({
  bulkEditTransactions: vi.fn(),
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

function Harness({
  hasMore = false,
  inline = false,
  resetKey = 'a',
  extended = false,
}: {
  hasMore?: boolean;
  inline?: boolean;
  resetKey?: string;
  extended?: boolean;
}) {
  const items = [tx(FIRST, 'Carrefour'), tx(SECOND, 'Monoprix')];
  if (extended) items.push(tx('third', 'Boulangerie'));
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
      {inline ? (
        <BulkSelectionPen
          active={active}
          bulk={bulk}
          inline
          onToggleActive={() => setActive(!active)}
        />
      ) : null}
      <BulkSelectionToolbar
        active={active}
        bulk={bulk}
        inline={inline}
        onToggleActive={() => setActive(!active)}
      />
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

  it('keeps selected ids when more rows are appended', () => {
    const { update } = renderHarness({ hasMore: true });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));
    update({ hasMore: true, extended: true });
    expect(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' })).toHaveProperty(
      'checked',
      true,
    );
    expect(screen.getByRole('checkbox', { name: 'Sélectionner Boulangerie' })).toHaveProperty(
      'checked',
      false,
    );
    expect(screen.getByText('1 mouvement sélectionné')).toBeTruthy();
  });

  it('leaves the mode with the pen, dropping checkboxes and the selection', () => {
    renderHarness();
    const pen = screen.getByRole('button', { name: 'Quitter la sélection' });
    expect(pen.getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));

    fireEvent.click(pen);
    expect(pen.getAttribute('aria-pressed')).toBe('false');
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeNull();

    fireEvent.click(pen);
    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('clears the selection when the result set changes', () => {
    const { update } = renderHarness();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));

    update({ resetKey: 'b' });

    expect(screen.getByText('Aucune sélection')).toBeTruthy();
  });

  it('keeps selection and the edit action apart from the pen the host places', () => {
    renderHarness({ inline: true });
    const pen = screen.getByRole('button', { name: 'Quitter la sélection' });
    const action = screen.getByRole('button', { name: 'Modifier' });
    const controls = action.closest('[data-bulk-controls]');
    expect(controls).toBeTruthy();
    expect(controls?.contains(pen)).toBe(false);
    expect(
      controls?.contains(
        screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }),
      ),
    ).toBe(true);
  });

  it('keeps the exit available when a changed filter empties the loaded selection', () => {
    const onToggle = vi.fn();
    render(<BulkSelectionToolbar active bulk={null} onToggleActive={onToggle} />);
    const exit = screen.getByRole('button', { name: 'Quitter la sélection' });
    expect(exit.hasAttribute('disabled')).toBe(false);
    fireEvent.click(exit);
    expect(onToggle).toHaveBeenCalledOnce();
  });

  it('keeps the edit action disabled until something is selected', () => {
    renderHarness();
    const action = screen.getByRole('button', { name: 'Modifier' });
    expect((action as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner Carrefour' }));
    expect((action as HTMLButtonElement).disabled).toBe(false);
  });

  it('offers every transaction matching the filters with the exact count', async () => {
    const { update } = renderHarness({ hasMore: true });
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
    update({ hasMore: false, extended: true });
    expect(screen.getByRole('checkbox', { name: 'Sélectionner Boulangerie' })).toHaveProperty(
      'checked',
      true,
    );
    expect(
      screen.getByText('Les 120 mouvements correspondant aux filtres sont sélectionnés'),
    ).toBeTruthy();
  });

  it('applies a category to the selected ids and reports the skipped ones', async () => {
    api.bulkEditTransactions.mockImplementation(() =>
      ok({
        requestedCount: 2,
        changed: 1,
        fieldsChanged: ['categoryId'],
        skipped: [{ id: SECOND, reason: 'CLOSED_PERIOD' }],
      }),
    );
    renderHarness();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));

    const dialog = screen.getByRole('dialog', { name: 'Modifier 2 transactions' });
    expect(within(dialog).getByText('Modification', { exact: true })).toBeTruthy();
    const apply = within(dialog).getByRole('button', { name: 'Appliquer' }) as HTMLButtonElement;
    expect(apply.disabled).toBe(true);
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Modifier la catégorie' }));
    expect(apply.disabled).toBe(true);
    await chooseCategory();
    expect(apply.disabled).toBe(false);
    fireEvent.click(apply);

    await waitFor(() => expect(within(dialog).getByText('1 transaction modifiée')).toBeTruthy());
    expect(within(dialog).getByText('Détail', { exact: true })).toBeTruthy();
    expect(api.bulkEditTransactions).toHaveBeenCalledWith(
      expect.objectContaining({
        body: { changes: { categoryId: CATEGORY }, scope: { type: 'IDS', ids: [FIRST, SECOND] } },
        headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
      }),
    );
    expect(within(dialog).getByText('1 transaction ignorée')).toBeTruthy();
    expect(within(dialog).getByText('Période clôturée')).toBeTruthy();
    expect(reloadAfterWrite).toHaveBeenCalled();
    const details = within(dialog).getByText('Période clôturée').closest('details');
    expect(details?.open).toBe(false);
    expect(within(details as HTMLElement).getByText(/^Monoprix, /)).toBeTruthy();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Terminer' }));
  });

  it('asks to narrow the filters when the scope exceeds the limit', async () => {
    api.countTransactions.mockImplementation(() => ok({ count: 800 }));
    api.bulkEditTransactions.mockImplementation(() =>
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
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Modifier la catégorie' }));
    await chooseCategory();
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer' }));

    expect(await screen.findByText(/Affinez vos filtres/)).toBeTruthy();
    expect(api.bulkEditTransactions).toHaveBeenCalledWith(
      expect.objectContaining({
        body: expect.objectContaining({
          scope: { type: 'FILTER', filters: { q: 'market' }, expectedCount: 800 },
        }),
      }),
    );
  });

  it('asks to confirm again with the new count when the matching set changed', async () => {
    api.bulkEditTransactions
      .mockImplementationOnce(() =>
        problem(409, {
          type: '/problems/transactions.bulk-scope-changed',
          status: 409,
          actualCount: 125,
        }),
      )
      .mockImplementationOnce(() =>
        ok({ requestedCount: 125, changed: 125, fieldsChanged: ['categoryId'], skipped: [] }),
      );
    renderHarness({ hasMore: true });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }));
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Sélectionner les 120 mouvements correspondant aux filtres',
      }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'Modifier la catégorie' }));
    await chooseCategory();
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer' }));

    expect(await screen.findByText(/Le nombre de mouvements a changé : 125/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Appliquer' }));

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalledTimes(2));
    expect(api.bulkEditTransactions.mock.calls[1]?.[0].body.scope.expectedCount).toBe(125);
  });
});

describe('bulk result', () => {
  afterEach(cleanup);

  it('names a skipped transaction missing from the loaded page generically', () => {
    render(
      <BulkEditResultView
        onDone={vi.fn()}
        pageItems={[]}
        result={{
          requestedCount: 1,
          changed: 0,
          fieldsChanged: [],
          impactedAccountIds: [],
          skipped: [{ id: FIRST, reason: 'SPLIT' }],
        }}
      />,
    );

    expect(screen.getByText('Transaction hors de la page chargée')).toBeTruthy();
  });
});
