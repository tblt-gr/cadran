import type { MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { buildExpenseBreakdown } from './expenseBreakdown';

function row(
  id: string,
  value: string | null,
  overrides: Partial<MonthlyLedgerCategoryRow> = {},
): MonthlyLedgerCategoryRow {
  return {
    archived: false,
    budgetIncluded: true,
    color: null,
    hasMovements: true,
    icon: null,
    id,
    label: id,
    movementCount: 1,
    total: { assetCode: value === null ? null : 'EUR', reason: null, value },
    ...overrides,
  };
}

describe('buildExpenseBreakdown', () => {
  it('computes exact shares, largest first', () => {
    const result = buildExpenseBreakdown(
      [row('Transport', '99.50'), row('Loyer', '600.00'), row('Courses', '300.50')],
      'fr',
    );

    expect(result.kind).toBe('ready');
    if (result.kind !== 'ready') return;
    expect(result.total).toBe('1000.00');
    expect(result.slices.map((slice) => [slice.label, slice.amount, slice.share])).toEqual([
      ['Loyer', '600.00', '0.60000000'],
      ['Courses', '300.50', '0.30050000'],
      ['Transport', '99.50', '0.09950000'],
    ]);
  });

  it('groups categories beyond the sixth into one "Other" slice with the exact sum', () => {
    const rows = [100, 90, 80, 70, 60, 50, 40, 30, 20].map((amount) =>
      row(`c${amount}`, `${amount}.00`),
    );
    const result = buildExpenseBreakdown(rows, 'fr');

    expect(result.kind).toBe('ready');
    if (result.kind !== 'ready') return;
    expect(result.slices).toHaveLength(7);
    const other = result.slices[6]!;
    expect(other.grouped).toBe(true);
    expect(other.amount).toBe('90.00');
    expect(other.share).toBe('0.16666666');
    expect(other.categoryIds).toEqual(['c40', 'c30', 'c20']);
  });

  it('keeps a lone seventh category as itself instead of an "Other" of one', () => {
    const rows = [70, 60, 50, 40, 30, 20, 10].map((amount) => row(`c${amount}`, `${amount}.00`));
    const result = buildExpenseBreakdown(rows, 'fr');

    expect(result.kind === 'ready' && result.slices.some((slice) => slice.grouped)).toBe(false);
    expect(result.kind === 'ready' && result.slices).toHaveLength(7);
  });

  it('is empty without any expense, and ignores categories outside the budget or without movement', () => {
    expect(buildExpenseBreakdown([], 'fr')).toEqual({ kind: 'empty' });
    expect(
      buildExpenseBreakdown(
        [
          row('a', '10.00', { budgetIncluded: false }),
          row('b', null, { hasMovements: false }),
          row('c', '-5.00'),
          row('d', '0.00'),
        ],
        'fr',
      ),
    ).toEqual({ kind: 'empty' });
  });

  it('is not calculable, never zero, when every category has mixed assets', () => {
    const result = buildExpenseBreakdown([row('a', null)], 'fr');

    expect(result).toEqual({ kind: 'notCalculable', undrawn: [{ key: 'a', label: 'a' }] });
  });

  it('lists a mixed-asset category apart and still draws the others', () => {
    const result = buildExpenseBreakdown([row('a', null), row('b', '10.00')], 'fr');

    expect(result.kind === 'ready' && result.undrawn).toEqual([{ key: 'a', label: 'a' }]);
    expect(result.kind === 'ready' && result.slices).toHaveLength(1);
  });

  it('is not calculable when drawn categories use different assets', () => {
    const result = buildExpenseBreakdown(
      [
        row('a', '10.00'),
        row('b', '5.00', { total: { assetCode: 'USD', reason: null, value: '5.00' } }),
      ],
      'fr',
    );

    expect(result.kind).toBe('notCalculable');
  });

  it('stays exact on decimals beyond the Number range', () => {
    const result = buildExpenseBreakdown(
      [row('a', '12345678901234567890.12'), row('b', '12345678901234567890.12')],
      'fr',
    );

    expect(result.kind === 'ready' && result.total).toBe('24691357802469135780.24');
    expect(result.kind === 'ready' && result.slices[0]!.share).toBe('0.50000000');
  });
});
