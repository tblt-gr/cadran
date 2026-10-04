import type { PortfolioOverviewSummary, PortfolioOverviewAllocation } from '@cadran/api-client';
export function summaryFixture(): PortfolioOverviewSummary {
  const explanation = {
    period: { from: '2026-01-01', to: '2026-03-10' },
    quality: 'COMPLETE',
    reason: null,
    sources: [{ kind: 'VALUATION' as const, id: 'valuation-1', date: '2026-03-10' }],
    truncatedSourceCount: 0,
  };
  return {
    dueMonths: [],
    newerPartialValuation: null,
    asOf: '2026-03-10',
    account: {
      id: 'account-1',
      label: 'PEA Démo',
      institution: null,
      productCode: null,
      assetCode: 'EUR',
      status: 'ACTIVE',
      openedOn: '2025-01-01',
    },
    valuation: {
      id: 'valuation-1',
      valuedOn: '2026-03-10',
      totalValue: '3000.421',
      completeness: 'COMPLETE',
      detail: 'POSITIONS',
      ageDays: 0,
      stale: false,
    },
    totalValue: '3000.421',
    cash: '529',
    unrealizedGain: {
      value: '121.421',
      securitiesValue: '2471.421',
      costBasis: '2350',
      reason: null,
    },
    netDeposits: {
      value: '3000',
      deposits: '3500',
      withdrawals: '500',
      from: '2026-01-01',
      to: '2026-03-10',
    },
    costs: { fees: '12', taxes: '3', total: '15', from: '2026-01-01', to: '2026-03-10' },
    reconciliation: { status: 'RECONCILED', warnings: [] },
    explanations: {
      TOTAL_VALUE: explanation,
      UNREALIZED_GAIN: explanation,
      CASH: explanation,
      NET_DEPOSITS: explanation,
      COSTS: explanation,
      RECONCILIATION: explanation,
    },
  };
}
export function allocationFixture(): PortfolioOverviewAllocation {
  return {
    newerPartialValuation: null,
    asOf: '2026-03-10',
    by: 'INSTRUMENT',
    assetCode: 'EUR',
    valuation: summaryFixture().valuation,
    total: '100',
    reason: null,
    items: [
      { key: 'UNSPECIFIED', label: 'Non renseignée', value: '75', share: '0.75' },
      { key: 'CASH', label: 'Espèces', value: '25', share: '0.25' },
    ],
    slices: [
      { key: 'UNSPECIFIED', label: 'Non renseignée', value: '75', share: '0.75' },
      { key: 'CASH', label: 'Espèces', value: '25', share: '0.25' },
    ],
  };
}
