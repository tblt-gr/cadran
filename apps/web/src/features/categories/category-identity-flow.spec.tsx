import type { Category, Transaction } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { TransactionList } from '@/features/transactions/transaction-list/TransactionList';
import { CategoryPage } from './CategoryPage';

const api = vi.hoisted(() => ({ listCategories: vi.fn(), updateCategory: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const category: Category = {
  id: '00000000-0000-7000-8000-0000000000c1',
  type: 'EXPENSE',
  label: 'Restaurants',
  parentId: null,
  parentLabel: null,
  icon: 'utensils',
  color: '#486DF0',
  defaultAnalyticAxes: [],
  budgetIncluded: true,
  sortOrder: 0,
  depth: 1,
  version: 1,
  used: false,
  typeEditable: true,
  typeEditReason: null,
  canAcceptChildren: true,
  archivedAt: null,
  replacement: null,
};

const transaction: Transaction = {
  id: '00000000-0000-7000-8000-0000000000d1',
  accountId: '00000000-0000-7000-8000-0000000000a1',
  amount: { value: '-12.50', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  authorizedOn: null,
  mcc: null,
  maskedCard: null,
  bankReference: null,
  createdAt: '2026-09-01T12:00:00+02:00',
  updatedAt: '2026-09-01T12:00:00+02:00',
  voidedAt: null,
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  bookedOn: '2026-09-01',
  rawLabel: 'Déjeuner',
  counterparty: null,
  note: null,
  valueOn: null,
  paymentMethod: null,
  splits: [
    {
      id: '00000000-0000-7000-8000-0000000000e1',
      categoryId: category.id,
      categoryLabel: category.label,
      categoryIcon: category.icon,
      categoryColor: category.color,
      amount: { value: '-12.50', assetCode: 'EUR' },
      analyticAxes: [],
      note: null,
      categorizationOrigin: 'MANUAL',
      categorizationRuleId: null,
    },
  ],
  version: 1,
};

function rows(transactions: Transaction[]) {
  return (
    <TransactionList
      accounts={[]}
      duplicatingIds={new Set()}
      onDuplicate={vi.fn()}
      onEdit={vi.fn()}
      onRefund={vi.fn()}
      onVoid={vi.fn()}
      transactions={transactions}
    />
  );
}

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response('{}') });
}

describe('category identity flow', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('refreshes list, combobox selection/options and transaction cells from a successful category edit', async () => {
    let current = category;
    api.listCategories.mockImplementation(() =>
      success({ items: [current], page: 1, perPage: 50, total: 1 }),
    );
    api.updateCategory.mockImplementation(({ body }) => {
      current = { ...category, ...body, version: 2 };
      return success(current);
    });
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    // Stands for a transactions page already in cache, which is what a category
    // edit has to reach.
    client.setQueryData(['transactions', {}], { items: [transaction], total: 1 });
    render(
      <QueryClientProvider client={client}>
        <CategoryPage />
        <CategoryPicker
          label="Catégorie choisie"
          onChange={vi.fn()}
          selectedLabel={category.label}
          type="EXPENSE"
          value={category.id}
        />
        {rows([transaction])}
      </QueryClientProvider>,
    );

    const selected = screen.getByRole('combobox', { name: 'Catégorie choisie' });
    const row = screen.getByRole('row', { name: /Déjeuner/ });
    // The swatch sits inside the control, beside the text: the value stays plain
    // editable text.
    await waitFor(() =>
      expect(selected.parentElement?.querySelector('[data-icon="utensils"]')).toBeTruthy(),
    );
    expect((row.querySelector('[data-icon="utensils"]') as HTMLElement).style.background).toBe(
      'rgb(72, 109, 240)',
    );
    fireEvent.focus(selected);
    expect(
      (await screen.findByRole('option', { name: 'Restaurants' })).querySelector(
        '[data-icon="utensils"]',
      ),
    ).toBeTruthy();
    fireEvent.keyDown(selected, { key: 'Escape' });
    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la catégorie « Restaurants »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Modifier la catégorie « Restaurants »' }));
    const dialog = screen.getByRole('dialog', { name: 'Modifier la catégorie' });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Sorties' } });
    fireEvent.click(within(dialog).getByRole('radio', { name: 'Tasse' }));
    fireEvent.change(within(dialog).getByLabelText('Code couleur'), {
      target: { value: '#58B8C0' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect((selected as HTMLInputElement).value).toBe('Sorties'));
    expect(selected.parentElement?.querySelector('[data-icon="coffee"]')).toBeTruthy();
    // Transaction rows carry the identity of their category, so a recoloured
    // category has to take the cached pages with it.
    await waitFor(() =>
      expect(client.getQueryState(['transactions', {}])?.isInvalidated).toBe(true),
    );
    const categoryRow = screen
      .getByRole('button', { name: 'Actions de la catégorie « Sorties »' })
      .closest('tr') as HTMLElement;
    expect(
      (categoryRow.querySelector('[data-icon="coffee"]') as HTMLElement).style.background,
    ).toBe('rgb(88, 184, 192)');
    fireEvent.focus(selected);
    expect(
      (await screen.findByRole('option', { name: 'Sorties' })).querySelector(
        '[data-icon="coffee"]',
      ),
    ).toBeTruthy();
  });

  it('keeps the label and a neutral pill for a category carrying no metadata', () => {
    const plain: Transaction = {
      ...transaction,
      splits: [{ ...transaction.splits[0], categoryIcon: null, categoryColor: null }],
    };
    render(rows([plain]));

    const row = screen.getByRole('row', { name: /Déjeuner/ });
    const pill = row.querySelector('[data-icon="none"]') as HTMLElement;

    expect(within(row).getByText('Restaurants')).toBeTruthy();
    expect(pill.querySelector('svg')).toBeNull();
    expect(pill.getAttribute('style')).toBeNull();
  });

  it('shows no identity pill for an uncategorised transaction', () => {
    render(rows([{ ...transaction, splits: [] }]));

    const row = screen.getByRole('row', { name: /Déjeuner/ });
    expect(within(row).getByText('À catégoriser')).toBeTruthy();
    expect(row.querySelector('[data-icon]')).toBeNull();
  });
});
