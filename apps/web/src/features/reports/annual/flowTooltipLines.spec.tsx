import { describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { flowTooltipLines } from './flowTooltipLines';

const t = i18n.t.bind(i18n) as (key: string) => string;

describe('flowTooltipLines', () => {
  it('shows the month label first, then income, expenses and balance as exact figures', () => {
    const lines = flowTooltipLines(
      {
        month: '2026-03',
        cashIncome: { value: '2000.10', reason: null },
        budgetExpenses: { value: '1000.05', reason: null },
        budgetSurplus: { value: '1000.05', reason: null },
      },
      'EUR',
      'fr',
      t,
    );

    expect(lines).toHaveLength(4);
    expect(lines[0]).toBe('Mars 2026');
    expect(lines[1]).toContain('Revenus de trésorerie');
    expect(lines[1]).toContain('2');
    expect(lines[2]).toContain('Dépenses budgétaires');
    expect(lines[3]).toContain('Solde budgétaire');
  });

  it('keeps the month and names a figure that is not calculable instead of showing zero', () => {
    const lines = flowTooltipLines(
      {
        month: '2026-12',
        cashIncome: { value: null, reason: 'FUTURE_MONTH' },
        budgetExpenses: { value: null, reason: 'FUTURE_MONTH' },
        budgetSurplus: { value: null, reason: 'FUTURE_MONTH' },
      },
      'EUR',
      'fr',
      t,
    );

    expect(lines[0]).toBe('Décembre 2026');
    expect(lines.slice(1).every((line) => line.endsWith('Non calculable'))).toBe(true);
  });
});
