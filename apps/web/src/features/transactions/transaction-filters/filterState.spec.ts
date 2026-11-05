import { describe, expect, it } from 'vitest';
import {
  DEFAULT_FILTERS,
  decodeFilters,
  encodeFilters,
  isImpossibleCombination,
  hasLegacyPeriod,
  hasPanelFilters,
  isValidAmountBound,
  normalizeAmountInput,
  resolvePeriod,
  shiftMonth,
  toListTransactionsQuery,
  type TransactionFilterState,
} from './filterState';

const ACCOUNT_A = '00000000-0000-7000-8000-0000000000a1';
const ACCOUNT_B = '00000000-0000-7000-8000-0000000000a2';
const CATEGORY = '00000000-0000-7000-8000-0000000000c1';

function withFilters(overrides: Partial<TransactionFilterState>): TransactionFilterState {
  return { ...DEFAULT_FILTERS, ...overrides };
}

describe('encodeFilters / decodeFilters', () => {
  it('reads a bare month, as a Budget link sends it, as that month', () => {
    const decoded = decodeFilters(new URLSearchParams('tab=operations&month=2026-08'));

    expect(decoded.period).toBe('month');
    expect(decoded.month).toBe('2026-08');
    expect(decodeFilters(new URLSearchParams('month=2026-13')).period).toBe('all');
  });

  it('round-trips the default filter set to an empty query string', () => {
    const params = encodeFilters(DEFAULT_FILTERS);

    expect(params.toString()).toBe('');
    expect(decodeFilters(params)).toEqual(DEFAULT_FILTERS);
  });

  it('round-trips a fully populated filter set', () => {
    const filters = withFilters({
      period: 'custom',
      from: '2026-01-01',
      to: '2026-01-31',
      direction: 'OUTFLOW',
      accountId: [ACCOUNT_A, ACCOUNT_B],
      state: ['BOOKED', 'PENDING'],
      nature: ['EXPENSE'],
      categoryId: [CATEGORY],
      includeDescendants: true,
      axis: ['ESSENTIAL', 'FIXED'],
      minAmount: '1.00',
      maxAmount: '500.00',
      assetCode: 'EUR',
      source: ['MANUAL'],
      categorization: 'NONE',
      q: 'carrefour',
      includeVoided: true,
    });

    const params = encodeFilters(filters);

    expect(decodeFilters(params)).toEqual(filters);
  });

  it('drops unknown enum members and a malformed date instead of throwing', () => {
    const params = new URLSearchParams(
      'period=custom&from=not-a-date&to=2026-01-31&state=UNKNOWN&state=BOOKED&axis=NOPE',
    );

    const decoded = decodeFilters(params);

    expect(decoded.from).toBe('');
    expect(decoded.to).toBe('2026-01-31');
    expect(decoded.state).toEqual(['BOOKED']);
    expect(decoded.axis).toEqual([]);
  });

  it('drops malformed identifiers, amounts, asset codes and an overlong search from a URL', () => {
    const params = new URLSearchParams(
      `accountId=foo&accountId=${ACCOUNT_A}&categoryId=1&minAmount=12.&maxAmount=abc&assetCode=eur&q=${'x'.repeat(90)}`,
    );

    const decoded = decodeFilters(params);

    expect(decoded.accountId).toEqual([ACCOUNT_A]);
    expect(decoded.categoryId).toEqual([]);
    expect(decoded.minAmount).toBe('');
    expect(decoded.maxAmount).toBe('');
    expect(decoded.assetCode).toBe('');
    expect(decoded.q).toHaveLength(80);
  });

  it('round-trips a single month period', () => {
    const filters = withFilters({ period: 'month', month: '2026-11' });
    const params = encodeFilters(filters);

    expect(params.toString()).toBe('period=month&month=2026-11');
    expect(decodeFilters(params)).toEqual(filters);
  });

  it('falls back to the current month for a month period without a valid month', () => {
    const today = new Date(2026, 10, 3);

    expect(decodeFilters(new URLSearchParams('period=month'), today).month).toBe('2026-11');
    expect(decodeFilters(new URLSearchParams('period=month&month=2026-13'), today).month).toBe(
      '2026-11',
    );
  });

  it('rewrites the legacy presets to a month relative to today', () => {
    const today = new Date(2026, 10, 3);

    const thisMonth = decodeFilters(new URLSearchParams('period=thisMonth'), today);
    expect(thisMonth.period).toBe('month');
    expect(thisMonth.month).toBe('2026-11');

    const lastMonth = decodeFilters(new URLSearchParams('period=lastMonth'), today);
    expect(lastMonth.month).toBe('2026-10');
    expect(encodeFilters(lastMonth).toString()).toBe('period=month&month=2026-10');

    expect(hasLegacyPeriod(new URLSearchParams('period=thisMonth'))).toBe(true);
    expect(hasLegacyPeriod(new URLSearchParams('period=month&month=2026-11'))).toBe(false);
  });

  it('keeps a known direction and drops an unknown one', () => {
    expect(decodeFilters(new URLSearchParams('direction=INFLOW')).direction).toBe('INFLOW');
    expect(decodeFilters(new URLSearchParams('direction=SIDEWAYS')).direction).toBe('');
  });

  it('drops a negative bound from a URL', () => {
    const decoded = decodeFilters(new URLSearchParams('minAmount=-10&maxAmount=50&assetCode=EUR'));

    expect(decoded.minAmount).toBe('');
    expect(decoded.maxAmount).toBe('50');
  });

  it('ignores from/to when the preset is not custom', () => {
    const params = new URLSearchParams('period=month&month=2026-11&from=2026-01-01&to=2026-01-31');

    const decoded = decodeFilters(params);

    expect(decoded.period).toBe('month');
    expect(decoded.from).toBe('');
    expect(decoded.to).toBe('');
  });

  it('falls back to categorization ANY for any value other than NONE', () => {
    const params = new URLSearchParams('categorization=WHATEVER');

    expect(decodeFilters(params).categorization).toBe('ANY');
  });
});

describe('categoryType', () => {
  it('round-trips through the query string, between nature and categoryId', () => {
    const params = encodeFilters(
      withFilters({ nature: ['INCOME'], categoryType: 'INCOME', categoryId: [CATEGORY] }),
    );

    expect(params.toString()).toBe(`nature=INCOME&categoryType=INCOME&categoryId=${CATEGORY}`);
    expect(decodeFilters(params).categoryType).toBe('INCOME');
  });

  it('drops any value other than INCOME or EXPENSE', () => {
    expect(decodeFilters(new URLSearchParams('categoryType=SAVINGS')).categoryType).toBe('');
    expect(decodeFilters(new URLSearchParams('categoryType=EXPENSE')).categoryType).toBe('EXPENSE');
  });

  it('reaches the API query only when set', () => {
    const today = new Date(2026, 8, 16);

    expect(toListTransactionsQuery(DEFAULT_FILTERS, today).categoryType).toBeUndefined();
    expect(
      toListTransactionsQuery(withFilters({ categoryType: 'EXPENSE' }), today).categoryType,
    ).toBe('EXPENSE');
  });
});

describe('hasPanelFilters', () => {
  it('ignores the search, the queue tab and the single month', () => {
    expect(hasPanelFilters(DEFAULT_FILTERS)).toBe(false);
    expect(
      hasPanelFilters(
        withFilters({ q: 'loyer', categorization: 'NONE', period: 'month', month: '2026-11' }),
      ),
    ).toBe(false);
  });

  it('ignores the natures and the category type a budget link brings', () => {
    expect(
      hasPanelFilters(
        withFilters({
          nature: ['EXPENSE', 'FEE', 'REFUND'],
          categoryType: 'EXPENSE',
          period: 'month',
          month: '2026-10',
        }),
      ),
    ).toBe(false);
    expect(hasPanelFilters(withFilters({ axis: ['ESSENTIAL'] }))).toBe(true);
  });

  it('sees every other filter', () => {
    expect(hasPanelFilters(withFilters({ categoryId: [CATEGORY] }))).toBe(true);
    expect(hasPanelFilters(withFilters({ direction: 'INFLOW' }))).toBe(true);
    expect(hasPanelFilters(withFilters({ period: 'thisYear' }))).toBe(true);
    expect(hasPanelFilters(withFilters({ includeVoided: true }))).toBe(true);
  });
});

describe('resolvePeriod', () => {
  const today = new Date(2026, 8, 16); // 2026-09-16

  it('returns no bound for "all"', () => {
    expect(resolvePeriod(withFilters({ period: 'all' }), today)).toEqual({
      from: null,
      to: null,
    });
  });

  it('resolves "month" to its first and last day', () => {
    expect(resolvePeriod(withFilters({ period: 'month', month: '2026-02' }), today)).toEqual({
      from: '2026-02-01',
      to: '2026-02-28',
    });
    expect(resolvePeriod(withFilters({ period: 'month', month: '2024-02' }), today)).toEqual({
      from: '2024-02-01',
      to: '2024-02-29',
    });
  });

  it('steps a month across year boundaries', () => {
    expect(shiftMonth('2026-01', -1)).toBe('2025-12');
    expect(shiftMonth('2026-12', 1)).toBe('2027-01');
  });

  it('resolves "this year" to January 1st through today', () => {
    expect(resolvePeriod(withFilters({ period: 'thisYear' }), today)).toEqual({
      from: '2026-01-01',
      to: '2026-09-16',
    });
  });

  it('resolves "custom" to the stored bounds', () => {
    expect(
      resolvePeriod(withFilters({ period: 'custom', from: '2026-02-01', to: '2026-02-10' }), today),
    ).toEqual({ from: '2026-02-01', to: '2026-02-10' });
  });
});

describe('isImpossibleCombination', () => {
  it('is false for the default filters', () => {
    expect(isImpossibleCombination(DEFAULT_FILTERS)).toBe(false);
  });

  it('is true for a custom period whose end precedes its start', () => {
    expect(
      isImpossibleCombination(
        withFilters({ period: 'custom', from: '2026-03-10', to: '2026-03-01' }),
      ),
    ).toBe(true);
  });

  it('is true when the uncategorised queue is combined with a category filter', () => {
    expect(
      isImpossibleCombination(withFilters({ categorization: 'NONE', categoryId: ['c1'] })),
    ).toBe(true);
  });

  it('is true when the uncategorised queue is combined with an axis filter', () => {
    expect(
      isImpossibleCombination(withFilters({ categorization: 'NONE', axis: ['ESSENTIAL'] })),
    ).toBe(true);
  });

  it('is true when the signed amount bounds are inverted', () => {
    expect(
      isImpossibleCombination(
        withFilters({ minAmount: '500.00', maxAmount: '1.00', assetCode: 'EUR' }),
      ),
    ).toBe(true);
  });

  it('is true when the to-categorise queue is restricted to voided movements', () => {
    expect(
      isImpossibleCombination(withFilters({ categorization: 'NONE', state: ['VOIDED'] })),
    ).toBe(true);
    expect(
      isImpossibleCombination(withFilters({ categorization: 'NONE', state: ['VOIDED', 'BOOKED'] })),
    ).toBe(false);
  });

  it('is false when amount bounds are ordered', () => {
    expect(
      isImpossibleCombination(
        withFilters({ minAmount: '1.00', maxAmount: '500.00', assetCode: 'EUR' }),
      ),
    ).toBe(false);
  });

  it('detects an inverted signed amount range at 24 decimal digits without float rounding', () => {
    // parseFloat would collapse both operands to 1 and miss this; the comparator must not.
    expect(
      isImpossibleCombination(
        withFilters({
          minAmount: '1.000000000000000000000001',
          maxAmount: '1.0',
          assetCode: 'EUR',
        }),
      ),
    ).toBe(true);
  });
});

describe('normalizeAmountInput', () => {
  it('converts a French comma decimal separator to a dot', () => {
    expect(normalizeAmountInput('200,00')).toBe('200.00');
  });

  it('removes a typed minus sign: bounds are unsigned', () => {
    expect(normalizeAmountInput('-200,00')).toBe('200.00');
  });

  it('leaves an already canonical value untouched', () => {
    expect(normalizeAmountInput('200.00')).toBe('200.00');
  });

  it('only replaces the first comma', () => {
    expect(normalizeAmountInput('12,34,56')).toBe('12.34,56');
  });
});

describe('isValidAmountBound', () => {
  it('accepts unsigned canonical decimals and refuses negative or malformed ones', () => {
    expect(isValidAmountBound('10')).toBe(true);
    expect(isValidAmountBound('10.50')).toBe(true);
    expect(isValidAmountBound('-10')).toBe(false);
    expect(isValidAmountBound('12.')).toBe(false);
  });
});

describe('toListTransactionsQuery', () => {
  const today = new Date(2026, 8, 16);

  it('sends no filter keys for the default state', () => {
    expect(toListTransactionsQuery(DEFAULT_FILTERS, today)).toEqual({
      from: undefined,
      to: undefined,
      accountId: undefined,
      state: undefined,
      nature: undefined,
      categoryId: undefined,
      includeDescendants: undefined,
      axis: undefined,
      direction: undefined,
      minAmount: undefined,
      maxAmount: undefined,
      assetCode: undefined,
      source: undefined,
      q: undefined,
      includeVoided: undefined,
      categorization: undefined,
    });
  });

  it('maps a populated filter set to the request query', () => {
    const filters = withFilters({
      period: 'month',
      month: '2026-09',
      direction: 'OUTFLOW',
      accountId: [ACCOUNT_A],
      state: ['BOOKED'],
      categorization: 'NONE',
      q: 'carrefour',
      includeVoided: false,
    });

    const query = toListTransactionsQuery(filters, today);

    expect(query.from).toBe('2026-09-01');
    expect(query.to).toBe('2026-09-30');
    expect(query.direction).toBe('OUTFLOW');
    expect(query.accountId).toEqual([ACCOUNT_A]);
    expect(query.state).toEqual(['BOOKED']);
    expect(query.categorization).toBe('NONE');
    expect(query.q).toBe('carrefour');
  });

  it('only sends amount bounds together with their asset code', () => {
    const withoutAsset = toListTransactionsQuery(withFilters({ minAmount: '200.00' }), today);
    expect(withoutAsset.minAmount).toBeUndefined();
    expect(withoutAsset.assetCode).toBeUndefined();

    const withAsset = toListTransactionsQuery(
      withFilters({ minAmount: '200.00', assetCode: 'EUR' }),
      today,
    );
    expect(withAsset.minAmount).toBe('200.00');
    expect(withAsset.assetCode).toBe('EUR');
  });

  it('never asks the to-categorise queue for voided movements', () => {
    expect(toListTransactionsQuery(withFilters({ includeVoided: true }), today).includeVoided).toBe(
      true,
    );
    expect(
      toListTransactionsQuery(withFilters({ categorization: 'NONE', includeVoided: true }), today)
        .includeVoided,
    ).toBeUndefined();
  });
});
