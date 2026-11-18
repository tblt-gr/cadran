import { describe, expect, it } from 'vitest';
import { axisTickLabel } from '@/lib/monthLabel';
import {
  columnGroup,
  firstDataMonth,
  summaryFigure,
  fromFirstDataMonth,
  groupStartIds,
  withoutLeadingEmptyMonths,
} from './annualColumns';
import { yearStartTicks } from './chartGeometry';

const row = (month: string, state: string) => ({ month, state });

describe('column families', () => {
  it('names the family from the id prefix, an indicator otherwise', () => {
    expect(
      ['cashIncome', 'axis:FIXED', 'category:1', 'account:2', 'group:3'].map(columnGroup),
    ).toEqual(['indicator', 'axis', 'category', 'account', 'group']);
  });

  it('marks the first column of each family after the first, for a vertical separator', () => {
    const ids = [
      'cashIncome',
      'budgetExpenses',
      'axis:FIXED',
      'category:a',
      'category:b',
      'account:x',
    ];
    expect([...groupStartIds(ids.map((id) => ({ id })))]).toEqual([
      'axis:FIXED',
      'category:a',
      'account:x',
    ]);
    expect(groupStartIds([]).size).toBe(0);
  });

  it('follows the user order: a separator opens wherever the family changes', () => {
    const ids = ['cashIncome', 'category:a', 'cashSavingsRate', 'category:b'];
    expect([...groupStartIds(ids.map((id) => ({ id })))]).toEqual([
      'category:a',
      'cashSavingsRate',
      'category:b',
    ]);
  });
});

describe('data that starts mid-year', () => {
  const rows = [
    row('2024-01', 'NO_DATA'),
    row('2024-08', 'NO_DATA'),
    row('2024-09', 'COMPLETE'),
    row('2024-10', 'NO_DATA'),
    row('2024-11', 'FUTURE'),
  ];

  it('drops the empty months before the first month with data only', () => {
    expect(withoutLeadingEmptyMonths(rows).map((entry) => entry.month)).toEqual([
      '2024-09',
      '2024-10',
      '2024-11',
    ]);
  });

  it('keeps everything when the first month already has data or none does', () => {
    expect(withoutLeadingEmptyMonths([row('2025-01', 'COMPLETE')])).toHaveLength(1);
    expect(
      withoutLeadingEmptyMonths([row('2025-01', 'NO_DATA'), row('2025-02', 'NO_DATA')]),
    ).toHaveLength(2);
    expect(withoutLeadingEmptyMonths([])).toEqual([]);
  });

  it('starts the chart series at the first month with data', () => {
    expect(firstDataMonth(rows)).toBe('2024-09');
    expect(firstDataMonth([row('2024-01', 'NO_DATA')])).toBeNull();
    const series = ['2024-01', '2024-08', '2024-09', '2024-12'].map((month) => ({ month }));
    expect(fromFirstDataMonth(series, '2024-09').map((entry) => entry.month)).toEqual([
      '2024-09',
      '2024-12',
    ]);
    expect(fromFirstDataMonth(series, null)).toHaveLength(4);
  });
});

describe('time axis of the monthly charts', () => {
  it('labels the first point and every January only', () => {
    const keys = ['09', '10', '11', '12', '01', '02'];
    expect(yearStartTicks(keys)).toEqual(['09', '01']);
    expect(yearStartTicks(['2024-09', '2024-12', '2025-01', '2025-02', '2026-01'])).toEqual([
      '2024-09',
      '2025-01',
      '2026-01',
    ]);
    expect(yearStartTicks([])).toEqual([]);
    // A series started mid-year labels its years only.
    expect(yearStartTicks(['2023-10', '2023-11', '2024-01', '2024-02'], false)).toEqual([
      '2024-01',
    ]);
  });

  it('writes a January as its year and any other month with its name', () => {
    expect(axisTickLabel('2025-01', 'fr')).toBe('2025');
    expect(axisTickLabel('2024-09', 'fr')).toBe('Septembre 2024');
  });
});

describe('summaryFigure', () => {
  const aggregate = {
    kind: 'STOCK',
    total: null,
    totalReason: 'NOT_ADDITIVE',
    periodEnd: { value: '1250.00', month: '2026-11' },
  } as unknown as Parameters<typeof summaryFigure>[0];

  it('reads the total of a balance as its latest month value, without naming the month', () => {
    expect(summaryFigure(aggregate, 'total')).toEqual({
      value: '1250.00',
      reason: null,
      month: null,
    });
  });

  it('keeps the sum for a flow and the reason for a balance without a latest month', () => {
    expect(
      summaryFigure({ ...aggregate, kind: 'FLOW', total: '10.00', totalReason: null }, 'total'),
    ).toEqual({ value: '10.00', reason: null, month: null });
    expect(summaryFigure({ ...aggregate, periodEnd: null }, 'total')).toEqual({
      value: null,
      reason: 'NOT_ADDITIVE',
      month: null,
    });
  });
});
