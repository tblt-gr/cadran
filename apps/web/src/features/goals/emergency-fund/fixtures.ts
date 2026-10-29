import type { EmergencyFundCoverage, EmergencyFundPolicy } from '@cadran/api-client';

export const coverageShortfall: EmergencyFundCoverage = {
  asOf: '2026-09-29',
  liquidAssets: {
    value: '10260.00',
    assetCode: 'EUR',
    reason: null,
    accounts: [
      {
        id: '00000000-0000-7000-8000-0000000000a1',
        label: 'Livret A',
        assetCode: 'EUR',
        value: '3000.00',
        valuedOn: '2026-09-20',
        stale: false,
      },
      {
        id: '00000000-0000-7000-8000-0000000000a2',
        label: 'LDDS',
        assetCode: 'EUR',
        value: '7260.00',
        valuedOn: '2026-07-01',
        stale: true,
      },
    ],
  },
  reference: {
    start: '2025-01',
    end: '2025-12',
    assetCode: 'EUR',
    usedMonths: 6,
    expectedMonths: 12,
    excludedMonths: [{ month: '2025-01', reason: 'NO_DATA' }],
    average: '2100.00',
    averageReason: null,
    months: [
      { month: '2025-01', value: null },
      { month: '2025-07', value: '2100.00' },
    ],
  },
  targetMonths: '6.0',
  target: { mode: 'MONTHS', amount: null, ratio: null, impliedMonths: '6.0', incomeAverage: null },
  requiredAmount: '12600.00',
  gap: '-2340.00',
  coveredMonths: '4.8857142857142857142857',
  coveredMonthsDisplay: '4.9',
  reason: 'STALE_VALUATION',
  quality: 'PARTIAL',
};

export const coverageNoAccount: EmergencyFundCoverage = {
  ...coverageShortfall,
  liquidAssets: { value: null, assetCode: null, reason: 'NO_ELIGIBLE_ACCOUNT', accounts: [] },
  gap: null,
  coveredMonths: null,
  coveredMonthsDisplay: null,
  reason: 'NO_ELIGIBLE_ACCOUNT',
  quality: 'NOT_CALCULABLE',
};

export const defaultPolicy: EmergencyFundPolicy = {
  essentialMode: 'AXIS',
  essentialCategories: [],
  referenceMode: 'PREVIOUS_YEAR',
  trailingMonths: null,
  targetMonths: '6.0',
  version: 0,
  targetMode: 'MONTHS',
  targetAmount: null,
  targetRatio: null,
};
