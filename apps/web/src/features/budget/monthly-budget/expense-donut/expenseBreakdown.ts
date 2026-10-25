import type { MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { compareDecimals, decimalRatio, sumDecimals } from '@/lib/decimal';

/** Categories drawn on their own; the smaller ones are grouped into "Other". */
const MAX_NAMED_SLICES = 6;

/** Share precision carried to the formatter; the screen rounds it to two digits. */
const SHARE_SCALE = 8;

export interface ExpenseSlice {
  amount: string;
  /** Effective category colour, null for the grouped slice or a category without one. */
  color: string | null;
  /** Categories the slice stands for: one, or every grouped one. */
  categoryIds: string[];
  key: string;
  label: string;
  /** Exact ratio of the slice in the month's drawn expenses, in [0, 1]. */
  share: string;
  /** True for the slice that groups the smaller categories. */
  grouped: boolean;
}

/** A budget category the donut cannot draw: its assets are mixed, so it has no amount. */
interface UndrawnExpense {
  key: string;
  label: string;
}

export type ExpenseBreakdown =
  | { kind: 'empty' }
  | { kind: 'notCalculable'; undrawn: UndrawnExpense[] }
  | {
      kind: 'ready';
      assetCode: string;
      slices: ExpenseSlice[];
      total: string;
      undrawn: UndrawnExpense[];
    };

/**
 * Builds the donut from the ledger's expense rows, which are already net of refunds and exact.
 * Only budget categories are drawn. The slices add up to the drawn categories only, so the total can exceed the panel total when a category is net-negative. A category
 * whose refunds cancel or exceed its spending has nothing to draw and is left out; a category
 * with mixed assets has no amount and is listed apart. Amounts are only added and divided as
 * exact decimals.
 */
export function buildExpenseBreakdown(
  rows: readonly MonthlyLedgerCategoryRow[],
  language: string,
): ExpenseBreakdown {
  const undrawn: UndrawnExpense[] = [];
  const drawn: Array<{ row: MonthlyLedgerCategoryRow; amount: string; assetCode: string }> = [];

  for (const row of rows) {
    if (!row.budgetIncluded || !row.hasMovements) continue;
    const { assetCode, value } = row.total;
    if (value === null || assetCode === null) {
      undrawn.push({ key: row.id, label: row.label });
    } else if (compareDecimals(value, '0') > 0) {
      drawn.push({ row, amount: value, assetCode });
    }
  }

  if (drawn.length === 0) {
    return undrawn.length === 0 ? { kind: 'empty' } : { kind: 'notCalculable', undrawn };
  }

  const assetCode = drawn[0]!.assetCode;
  if (drawn.some((item) => item.assetCode !== assetCode)) {
    return { kind: 'notCalculable', undrawn };
  }

  drawn.sort(
    (left, right) =>
      compareDecimals(right.amount, left.amount) ||
      left.row.label.localeCompare(right.row.label, language),
  );
  const total = sumDecimals(drawn.map((item) => item.amount));
  // A lone remaining category is shown as itself: "Other" always groups at least two.
  const named = drawn.length > MAX_NAMED_SLICES + 1 ? drawn.slice(0, MAX_NAMED_SLICES) : drawn;
  const rest = drawn.slice(named.length);

  const slices: ExpenseSlice[] = named.map((item) => ({
    amount: item.amount,
    categoryIds: [item.row.id],
    color: item.row.color,
    grouped: false,
    key: item.row.id,
    label: item.row.label,
    share: decimalRatio(item.amount, total, SHARE_SCALE),
  }));
  if (rest.length > 0) {
    const amount = sumDecimals(rest.map((item) => item.amount));
    slices.push({
      amount,
      categoryIds: rest.map((item) => item.row.id),
      color: null,
      grouped: true,
      key: 'other',
      label: '',
      share: decimalRatio(amount, total, SHARE_SCALE),
    });
  }

  return { kind: 'ready', assetCode, slices, total, undrawn };
}
