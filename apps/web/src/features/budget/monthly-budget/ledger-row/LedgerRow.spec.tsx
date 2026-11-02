import type { MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LedgerRow } from './LedgerRow';

const api = vi.hoisted(() => ({ readMovements: vi.fn() }));
vi.mock('@cadran/api-client', () => ({ readMonthlyLedgerMovements: api.readMovements }));

function category(overrides: Partial<MonthlyLedgerCategoryRow> = {}): MonthlyLedgerCategoryRow {
  return {
    id: 'cat-1',
    label: 'Courses',
    icon: null,
    color: null,
    budgetIncluded: true,
    archived: false,
    total: { value: '-30.00', assetCode: 'EUR', reason: null },
    movementCount: 1,
    hasMovements: true,
    pendingAmount: null,
    pendingCount: 0,
    ...overrides,
  };
}

function renderRow(row: MonthlyLedgerCategoryRow) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  render(
    <QueryClientProvider client={client}>
      <ul>
        <LedgerRow
          actionsAllowed
          axis={null}
          kind="expense"
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
});
afterEach(cleanup);

describe('LedgerRow pending movements', () => {
  it('shows nothing pending and keeps the row unchanged without pending movements', () => {
    const item = renderRow(category());

    expect(item.textContent).not.toMatch(/en attente/);
    expect(item.hasAttribute('data-pending')).toBe(false);
    expect(item.className).not.toContain('rowPending');
  });

  it('leaves a mixed row with its realised amount and no pending marker or amount', () => {
    const item = renderRow(
      category({ pendingAmount: { value: '-12.50', assetCode: 'EUR' }, pendingCount: 1 }),
    );

    expect(item.textContent).toMatch(/30,00/);
    expect(item.textContent).not.toMatch(/12,50|en attente/);
    expect(item.className).not.toContain('rowPending');
    expect(within(item).getByRole('button', { name: /Afficher les mouvements/ })).toBeTruthy();
  });

  it('greys a pending-only row, marks it in plain text and shows the pending amount as usual', () => {
    const item = renderRow(
      category({
        total: { value: '0.00', assetCode: 'EUR', reason: null },
        movementCount: 0,
        hasMovements: false,
        pendingAmount: { value: '-40.00', assetCode: 'EUR' },
        pendingCount: 2,
      }),
    );

    expect(item.className).toContain('rowPending');
    expect(item.className).not.toContain('rowEmpty');
    expect(within(item).getByText('(en attente)')).toBeTruthy();
    // The pending amount is in the amount cell, in the booked amount's format, with no parentheses.
    const amount = item.querySelector('[data-visual-mask]');
    expect(amount?.textContent).toMatch(/40,00/);
    expect(amount?.textContent).not.toMatch(/[()]/);
    expect(item.querySelectorAll('[data-visual-mask]')).toHaveLength(1);
    fireEvent.click(within(item).getByRole('button', { name: /Afficher les mouvements/ }));
    expect(api.readMovements).toHaveBeenCalledOnce();
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
    expect(within(item).getByText('(en attente)')).toBeTruthy();
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
