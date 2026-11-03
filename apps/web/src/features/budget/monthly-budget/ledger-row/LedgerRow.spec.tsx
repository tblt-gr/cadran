import type { MonthlyLedgerAccountRow, MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LedgerRow } from './LedgerRow';

const api = vi.hoisted(() => ({ readMovements: vi.fn(), readPreferences: vi.fn() }));
vi.mock('@cadran/api-client', () => ({
  readInterfacePreferences: api.readPreferences,
  readMonthlyLedgerMovements: api.readMovements,
}));

function category(overrides: Partial<MonthlyLedgerCategoryRow> = {}): MonthlyLedgerCategoryRow {
  return {
    id: 'cat-1',
    label: 'Courses',
    icon: null,
    color: null,
    budgetIncluded: true,
    archived: false,
    total: { value: '30.00', assetCode: 'EUR', reason: null },
    movementCount: 1,
    hasMovements: true,
    pendingAmount: null,
    pendingCount: 0,
    ...overrides,
  };
}

function account(overrides: Partial<MonthlyLedgerAccountRow> = {}): MonthlyLedgerAccountRow {
  return {
    id: 'acc-1',
    label: 'Livret A',
    assetCode: 'EUR',
    kind: 'SAVINGS',
    primaryGroupColor: null,
    total: { value: '100.00', assetCode: 'EUR', reason: null },
    movementCount: 1,
    hasMovements: true,
    pendingAmount: null,
    pendingCount: 0,
    ...overrides,
  };
}

function renderRow(
  row: MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow,
  kind: 'expense' | 'account' = 'expense',
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  render(
    <QueryClientProvider client={client}>
      <ul>
        <LedgerRow
          actionsAllowed
          axis={null}
          kind={kind}
          language="fr"
          month="2026-10"
          onAdd={vi.fn()}
          row={row}
        />
      </ul>
    </QueryClientProvider>,
  );

  return screen.getByRole('listitem');
}

beforeEach(() => {
  api.readMovements.mockReset().mockImplementation(() => new Promise(() => undefined));
  api.readPreferences.mockReset().mockResolvedValue({
    data: { includeUpcoming: true, version: 0 },
    response: new Response('{}', { status: 200 }),
  });
});
afterEach(cleanup);

describe('LedgerRow pending movements', () => {
  it('shows a refund-only category as a positive movement', () => {
    const item = renderRow(
      category({ total: { value: '-18.00', assetCode: 'EUR', reason: null } }),
    );
    expect(item.querySelector('[data-visual-mask]')?.textContent).toMatch(/^18,00/);
  });

  it('shows an expense category as a negative movement', () => {
    const item = renderRow(category());
    expect(item.querySelector('[data-visual-mask]')?.textContent).toMatch(/^-30,00/);
  });
  it('shows nothing pending and keeps the row unchanged without pending movements', () => {
    const item = renderRow(category());

    expect(item.textContent).not.toMatch(/en attente/);
    expect(item.hasAttribute('data-pending')).toBe(false);
    expect(item.className).not.toContain('rowPending');
  });

  it('leaves a mixed row with its realised amount and no pending marker or amount', () => {
    const item = renderRow(
      category({ pendingAmount: { value: '12.50', assetCode: 'EUR' }, pendingCount: 1 }),
    );

    expect(item.textContent).toMatch(/30,00/);
    expect(item.textContent).not.toMatch(/12,50|en attente/);
    expect(item.className).not.toContain('rowPending');
    expect(within(item).getByRole('button', { name: /Afficher les mouvements/ })).toBeTruthy();
  });

  it('greys a pending-only row, with no marker, and shows the pending amount as usual', async () => {
    const item = renderRow(
      category({
        total: { value: '0.00', assetCode: 'EUR', reason: null },
        movementCount: 0,
        hasMovements: false,
        pendingAmount: { value: '40.00', assetCode: 'EUR' },
        pendingCount: 2,
      }),
    );

    expect(item.className).toContain('rowPending');
    expect(item.className).not.toContain('rowEmpty');
    expect(within(item).queryByText('(en attente)')).toBeNull();
    // The pending amount is in the amount cell, in the booked amount's format, with no parentheses.
    const amount = item.querySelector('[data-visual-mask]');
    expect(amount?.textContent).toMatch(/^-40,00/);
    expect(amount?.textContent).not.toMatch(/[()]/);
    expect(item.querySelectorAll('[data-visual-mask]')).toHaveLength(1);
    fireEvent.click(within(item).getByRole('button', { name: /Afficher les mouvements/ }));
    await waitFor(() => expect(api.readMovements).toHaveBeenCalledOnce());
  });

  it('preserves the sign of a pending refund in an expense category', () => {
    const item = renderRow(
      category({
        total: { value: '0.00', assetCode: 'EUR', reason: null },
        movementCount: 0,
        hasMovements: false,
        pendingAmount: { value: '-18.00', assetCode: 'EUR' },
        pendingCount: 1,
      }),
    );
    expect(item.querySelector('[data-visual-mask]')?.textContent).toMatch(/^18,00/);
  });

  it('shows the usual non-calculable dash when pending amounts are of mixed assets', () => {
    const item = renderRow(
      category({
        total: { value: '0.00', assetCode: 'EUR', reason: null },
        movementCount: 0,
        hasMovements: false,
        pendingAmount: null,
        pendingCount: 2,
      }),
    );

    expect(within(item).getByText('Montant en attente non calculable')).toBeTruthy();
    expect(within(item).queryByText('(en attente)')).toBeNull();
  });

  it('keeps a row without any movement empty and not expandable', () => {
    const item = renderRow(
      category({
        total: { value: null, assetCode: null, reason: 'NO_MOVEMENTS' },
        movementCount: 0,
        hasMovements: false,
      }),
    );

    expect(item.className).toContain('rowEmpty');
    expect(within(item).queryByRole('button', { name: /mouvements/ })).toBeNull();
  });
});

describe('LedgerRow left border color', () => {
  it('uses the effective group color on an account row', () => {
    const item = renderRow(account({ primaryGroupColor: '#2F9E6E' }), 'account');

    expect(item.style.borderInlineStartColor).toBe('rgb(47, 158, 110)');
  });

  it('leaves the border untouched for an account without a group color', () => {
    const item = renderRow(account(), 'account');

    expect(item.getAttribute('style')).toBeNull();
  });

  it('ignores a malformed account color instead of falling back to a muted token', () => {
    const item = renderRow(account({ primaryGroupColor: 'red' }), 'account');

    expect(item.getAttribute('style')).toBeNull();
  });

  it('keeps the category color on category rows', () => {
    const item = renderRow(category({ color: '#58B8C0' }));

    expect(item.style.borderInlineStartColor).toBe('rgb(88, 184, 192)');
  });
});
