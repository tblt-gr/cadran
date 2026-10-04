import type { PortfolioValuationHistory, PortfolioValuationHistoryPoint } from '@cadran/api-client';

export function historyPointFixture(
  overrides: Partial<PortfolioValuationHistoryPoint> = {},
): PortfolioValuationHistoryPoint {
  return {
    valuationId: '00000000-0000-7000-8000-000000000a01',
    valuedOn: '2026-07-31',
    detail: 'TOTAL',
    totalValue: { value: '3500.00', assetCode: 'EUR' },
    securitiesValue: { value: '3000.00', assetCode: 'EUR' },
    cash: { value: '500.00', assetCode: 'EUR' },
    completeness: 'COMPLETE',
    priceQuality: 'NOT_DETAILED',
    flags: [],
    ...overrides,
  };
}

/** The acceptance series: a total, a partial valuation without total, then a by-line one. */
export function historyFixture(
  overrides: Partial<PortfolioValuationHistory> = {},
): PortfolioValuationHistory {
  return {
    from: null,
    to: '2026-10-03',
    assetCode: 'EUR',
    truncated: false,
    points: [
      historyPointFixture(),
      historyPointFixture({
        valuationId: '00000000-0000-7000-8000-000000000a02',
        valuedOn: '2026-08-31',
        detail: 'POSITIONS',
        totalValue: null,
        securitiesValue: null,
        completeness: 'PARTIAL',
        priceQuality: 'MISSING',
      }),
      historyPointFixture({
        valuationId: '00000000-0000-7000-8000-000000000a03',
        valuedOn: '2026-09-30',
        detail: 'POSITIONS',
        totalValue: { value: '3664.701', assetCode: 'EUR' },
        securitiesValue: { value: '3100.701', assetCode: 'EUR' },
        cash: { value: '564.00', assetCode: 'EUR' },
        priceQuality: 'STALE',
        flags: ['STALE_PRICES', 'POSITIONS_CHANGED'],
      }),
    ],
    ...overrides,
  };
}
