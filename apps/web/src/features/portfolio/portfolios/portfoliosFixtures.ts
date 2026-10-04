import type { PortfolioOverviewAccountCard, PortfolioOverviewList } from '@cadran/api-client';

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
    unrealizedGain: {
      value: '219.5',
      costBasis: '2999.1',
      performance: '0.073189',
      reason: null,
    },
    dueMonths: [],
    newerPartialValuation: null,
    currentCash: '100',
    positionCount: 1,
    ...overrides,
  };
}

export function totalFixture(
  overrides: Partial<PortfolioOverviewList['total']> = {},
): PortfolioOverviewList['total'] {
  return {
    assetCode: 'EUR',
    totalValue: '4500.00',
    unrealizedGain: '400.00',
    costBasis: '4100.00',
    performance: '0.097561',
    valuedAccounts: 2,
    unvaluedAccounts: 0,
    oldestValuedOn: '2026-09-30',
    partialNewerAccounts: 0,
    reason: null,
    ...overrides,
  };
}
