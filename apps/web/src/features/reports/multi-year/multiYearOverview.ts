import type {
  AnnualCategoryShare,
  AnnualReport,
  AnnualTopExpenseCategories,
} from '@cadran/api-client';
import { compareDecimals, decimalRatio, isZeroDecimal, sumDecimals } from '@/lib/decimal';

const SHARE_SCALE = 6;

/**
 * Top expense categories of the whole range: each year's server-supplied category totals added
 * per category in exact decimals, shares re-derived from the summed whole. A year whose chart
 * is not calculable is left out; with none calculable, the first reason is passed on.
 */
export function overviewTopCategories(
  reports: readonly AnnualReport[],
): AnnualTopExpenseCategories {
  const usable = reports.filter(
    ({ charts }) =>
      charts.topExpenseCategories.reason === null && charts.topExpenseCategories.assetCode !== null,
  );
  const first = usable[0]?.charts.topExpenseCategories;
  if (!first) {
    return {
      assetCode: null,
      items: [],
      other: null,
      reason: reports[0]?.charts.topExpenseCategories.reason ?? 'NO_DATA',
    };
  }

  const byKey = new Map<string, { item: AnnualCategoryShare; totals: string[] }>();
  for (const { charts } of usable) {
    const { items, other } = charts.topExpenseCategories;
    for (const item of [...items, ...(other?.items ?? [])]) {
      const key = item.categoryId ?? item.label ?? '';
      const entry = byKey.get(key);
      if (entry) entry.totals.push(item.total);
      else byKey.set(key, { item, totals: [item.total] });
    }
  }

  const totals = [...byKey.values()].map(({ item, totals: parts }) => ({
    item,
    total: sumDecimals(parts),
  }));
  const whole = sumDecimals(totals.map(({ total }) => total));
  const rows = totals
    .map(({ item, total }) => ({
      ...item,
      total,
      share: isZeroDecimal(whole) ? '0' : decimalRatio(total, whole, SHARE_SCALE),
    }))
    .sort((a, b) => compareDecimals(b.total, a.total));

  return { assetCode: first.assetCode, items: rows, other: null, reason: null };
}
