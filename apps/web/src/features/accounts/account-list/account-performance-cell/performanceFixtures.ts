import type { PortfolioOverviewAccountCard, PortfolioReturnTrend } from '@cadran/api-client';

const WINDOW = [
  '2025-10',
  '2025-11',
  '2025-12',
  '2026-01',
  '2026-02',
  '2026-03',
  '2026-04',
  '2026-05',
  '2026-06',
  '2026-07',
  '2026-08',
  '2026-09',
] as const;

type Index = string | null;

/** Indexes for the 12 months of the window `2025-10` to `2026-09`, oldest first. */
export function trendFixture(
  indexes: readonly Index[],
  overrides: Partial<PortfolioReturnTrend> = {},
): PortfolioReturnTrend {
  return {
    months: WINDOW.map((month, position) => ({
      month,
      index: indexes[position] ?? null,
    })) as PortfolioReturnTrend['months'],
    from: null,
    to: '2026-09',
    cumulativeReturn: null,
    reason: null,
    reasonMonth: null,
    ...overrides,
  };
}

/** Worked example 1: chain from July 2026 with rates 0.01, -0.02, 0.03. */
export function julyTrend(): PortfolioReturnTrend {
  return trendFixture(
    [null, null, null, null, null, null, null, null, null, '101.00', '98.98', '101.95'],
    {
      from: '2026-07',
      cumulativeReturn: '0.019494',
    },
  );
}

export function accountCardFixture(
  overrides: Partial<PortfolioOverviewAccountCard> = {},
): PortfolioOverviewAccountCard {
  return {
    accountId: 'account-1',
    label: 'Mon PEA',
    institution: 'Courtier',
    productCode: 'PEA',
    assetCode: 'EUR',
    status: 'ACTIVE',
    latestValuation: {
      id: 'valuation-1',
      valuedOn: '2026-09-30',
      totalValue: '3000.42',
      completeness: 'COMPLETE',
      detail: 'POSITIONS',
      ageDays: 4,
      stale: false,
    },
    unrealizedGain: { value: '219.5', costBasis: '2999.1', performance: '0.073189', reason: null },
    dueMonths: [],
    newerPartialValuation: null,
    currentCash: '100',
    positionCount: 1,
    returnTrend: julyTrend(),
    ...overrides,
  };
}
