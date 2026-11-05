import { describe, expect, it } from 'vitest';
import { monthTransactionsHref } from './monthTransactionsHref';

describe('monthTransactionsHref', () => {
  it('lists the income transactions on income categories over the month', () => {
    expect(monthTransactionsHref('income', '2026-10', null)).toBe(
      '/transactions?nature=INCOME&categoryType=INCOME&period=month&month=2026-10',
    );
  });

  it('lists expenses, fees and refunds on expense categories over the month', () => {
    expect(monthTransactionsHref('expense', '2026-10', null)).toBe(
      '/transactions?nature=EXPENSE&nature=FEE&nature=REFUND&categoryType=EXPENSE&period=month&month=2026-10',
    );
  });

  it('adds the selected axis to the expense link only', () => {
    expect(monthTransactionsHref('expense', '2026-10', 'ESSENTIAL')).toBe(
      '/transactions?nature=EXPENSE&nature=FEE&nature=REFUND&categoryType=EXPENSE&axis=ESSENTIAL&period=month&month=2026-10',
    );
    expect(monthTransactionsHref('income', '2026-10', 'ESSENTIAL')).not.toContain('axis');
  });

  it('lists the transfers of the month without a category filter', () => {
    expect(monthTransactionsHref('account', '2026-10', null)).toBe(
      '/transactions?nature=TRANSFER&period=month&month=2026-10',
    );
  });
});
