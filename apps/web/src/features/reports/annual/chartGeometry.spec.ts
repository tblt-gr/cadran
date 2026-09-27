import { describe, expect, it } from 'vitest';
import { flowChartData, scaleForGeometry } from './chartGeometry';

describe('chart geometry', () => {
  it('scales every flow series against one shared maximum', () => {
    const months = [
      {
        month: '2026-01',
        cashIncome: { value: '2000', reason: null },
        budgetExpenses: { value: '1000', reason: null },
        budgetSurplus: { value: '-500', reason: null },
      },
      {
        month: '2026-02',
        cashIncome: { value: null, reason: 'FUTURE_MONTH' },
        budgetExpenses: { value: null, reason: 'FUTURE_MONTH' },
        budgetSurplus: { value: null, reason: 'FUTURE_MONTH' },
      },
    ];
    const [first, second] = flowChartData(months);
    expect(first).toMatchObject({ cashIncome: 1, budgetExpenses: 0.5, budgetSurplus: -0.25 });
    expect(second).toMatchObject({ cashIncome: null });
  });

  it('keeps zero for an all-zero series', () => {
    expect(scaleForGeometry(['0', '0'])).toEqual([0, 0]);
  });
});
