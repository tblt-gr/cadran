import type { InstrumentHolding } from '@cadran/api-client';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
export function instrumentSheetFixture(): InstrumentHolding {
  const holding = holdingsFixture().items[0]!;
  return {
    instrument: {
      ...holding.instrument,
      venueMic: 'XPAR',
      instrumentType: 'ETF',
      assetClass: 'EQUITY',
      region: 'WORLD',
      currencyCode: 'EUR',
      pricePrecision: 4,
      source: 'MANUAL',
      reviewed: true,
      reviewedAt: null,
      used: true,
      version: 1,
    },
    holding: { ...holding, quantity: '6', value: '630.00', unrealizedGain: '28.80' },
    accounts: [
      {
        accountId: '00000000-0000-7000-8000-0000000000a1',
        label: 'CTO',
        quantity: '6',
        value: '630.00',
        unrealizedGain: '28.80',
        realizedGain: '39.20',
        income: '15.00',
        feesAndTaxes: '-5.50',
        operationCount: 3,
        firstOperationOn: '2026-01-01',
        lastOperationOn: '2026-10-01',
        reason: null,
      },
    ],
    totals: {
      realizedGain: '39.20',
      income: '15.00',
      feesAndTaxes: '-5.50',
      totalGain: '77.50',
      reason: null,
    },
    truncated: false,
  };
}
