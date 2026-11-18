import { describe, expect, it } from 'vitest';
import { expenseTransactionsHref, monthBounds } from './expenseTransactionsHref';

describe('expenseTransactionsHref', () => {
  const period = monthBounds('2026-02');

  it('bounds the month, leap years included', () => {
    expect(monthBounds('2024-02')).toEqual({ from: '2024-02-01', to: '2024-02-29' });
    expect(period).toEqual({ from: '2026-02-01', to: '2026-02-28' });
  });

  it('keeps the sub-categories of a parent only when asked', () => {
    const plain = new URL(expenseTransactionsHref(['c1'], period, null)!, 'https://x.test');
    expect(plain.searchParams.get('includeDescendants')).toBeNull();
    const parent = new URL(expenseTransactionsHref(['c1'], period, null, true)!, 'https://x.test');
    expect(parent.searchParams.get('includeDescendants')).toBe('1');
    expect(parent.searchParams.getAll('categoryId')).toEqual(['c1']);
  });
});
