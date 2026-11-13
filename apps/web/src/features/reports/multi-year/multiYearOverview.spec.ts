import type { AnnualReport } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { overviewTopCategories } from './multiYearOverview';

function report(top: AnnualReport['charts']['topExpenseCategories']): AnnualReport {
  return { charts: { topExpenseCategories: top } } as unknown as AnnualReport;
}

const item = (categoryId: string, total: string) => ({
  categoryId,
  label: categoryId,
  color: null,
  total,
  share: '0',
});

describe('overviewTopCategories', () => {
  it('adds each category over the years in exact decimals and derives the shares', () => {
    const result = overviewTopCategories([
      report({
        assetCode: 'EUR',
        reason: null,
        items: [item('a', '0.10')],
        other: { color: null, total: '0.05', share: '0', items: [item('b', '0.05')] },
      }),
      report({
        assetCode: 'EUR',
        reason: null,
        items: [item('a', '0.20'), item('b', '0.05')],
        other: null,
      }),
    ]);

    expect(result.items.map(({ categoryId, total }) => [categoryId, total])).toEqual([
      ['a', '0.30'],
      ['b', '0.10'],
    ]);
    expect(result.items[0]!.share).toBe('0.750000');
    expect(result.other).toBeNull();
  });

  it('skips a year that is not calculable and passes the reason on when none is', () => {
    const bad = report({ assetCode: null, reason: 'NO_DATA', items: [], other: null });
    const good = report({ assetCode: 'EUR', reason: null, items: [item('a', '5')], other: null });

    expect(overviewTopCategories([bad, good]).items).toHaveLength(1);
    expect(overviewTopCategories([bad]).reason).toBe('NO_DATA');
  });
});
