import { describe, expect, it } from 'vitest';
import { budgetContextHref, budgetRegisterHref, readBudgetContext } from './budgetNavigation';
import { decodeFilters } from '@/features/transactions/transaction-filters/filterState';

describe('budget register context', () => {
  it('carries month and explicit category branch in visible register filters without an implicit axis', () => {
    const href = budgetRegisterHref('2026-09', 'ESSENTIAL', {
      categoryId: ['00000000-0000-7000-8000-000000000001'],
      includeDescendants: true,
      state: ['BOOKED'],
    });
    const search = href.slice(href.indexOf('?'));
    const filters = decodeFilters(new URLSearchParams(search));
    expect(filters).toMatchObject({
      period: 'month',
      month: '2026-09',
      includeDescendants: true,
      state: ['BOOKED'],
      axis: [],
    });
  });
  it('reads a valid budget context back and drops an invalid one', () => {
    expect(readBudgetContext('?budgetMonth=2026-09&budgetAxis=FIXED')).toEqual({
      month: '2026-09',
      axis: 'FIXED',
    });
    expect(readBudgetContext('?budgetMonth=2026-13&budgetAxis=nope')).toEqual({
      month: null,
      axis: null,
    });
  });
  it('carries the budget context to a sibling tab only when there is one', () => {
    expect(budgetContextHref('/transactions/categories', '2026-09', 'FIXED')).toBe(
      '/transactions/categories?budgetMonth=2026-09&budgetAxis=FIXED',
    );
    expect(budgetContextHref('/transactions/recurrences', null, null)).toBe(
      '/transactions/recurrences',
    );
  });
});
