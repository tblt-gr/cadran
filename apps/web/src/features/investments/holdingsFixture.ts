import type { Holdings } from '@cadran/api-client';

const PEA = '00000000-0000-7000-8000-0000000000a1';
const CTO = '00000000-0000-7000-8000-0000000000a2';
const TOTAL_ACCOUNT = '00000000-0000-7000-8000-0000000000a3';

/** The worked example of the aggregation: W on two accounts, A with an unknown cost. */
export function holdingsFixture(overrides: Partial<Holdings> = {}): Holdings {
  return {
    asOf: '2026-11-07',
    assetCode: 'EUR',
    valuedFrom: '2026-10-31',
    valuedTo: '2026-11-03',
    quality: 'DEGRADED',
    warnings: [{ code: 'MIXED_VALUATION_DATES', accountIds: [PEA, CTO] }],
    totals: {
      securitiesValue: '2510.00',
      costBasis: null,
      unrealizedGain: null,
      performance: null,
      cash: '170.00',
      totalValue: '2680.00',
      reason: 'UNKNOWN_LOT_COST',
    },
    items: [
      {
        instrument: {
          id: '00000000-0000-7000-8000-0000000000b1',
          name: 'MSCI World',
          isin: 'IE00B4L5Y983',
          ticker: 'W',
          status: 'ACTIVE',
          quantityPrecision: 6,
        },
        instrumentType: 'ETF',
        quantity: '15',
        value: '1510.00',
        costBasis: '1380.00',
        unrealizedGain: '130.00',
        performance: '0.094203',
        averageUnitCost: '92.0000',
        reason: null,
        lastPrice: { price: '102.0000', priceDate: '2026-11-03', priceQuality: 'CURRENT' },
        share: { ratio: '0.601594', percentDisplay: '60.16', reason: null },
        accounts: [
          {
            accountId: PEA,
            label: 'PEA',
            quantity: '10',
            value: '1000.00',
            costBasis: '900.00',
            unrealizedGain: '100.00',
            performance: '0.111111',
            valuedOn: '2026-10-31',
            priceDate: '2026-10-31',
            priceQuality: 'CURRENT',
          },
          {
            accountId: CTO,
            label: 'CTO',
            quantity: '5',
            value: '510.00',
            costBasis: '480.00',
            unrealizedGain: '30.00',
            performance: '0.0625',
            valuedOn: '2026-11-03',
            priceDate: '2026-11-03',
            priceQuality: 'CURRENT',
          },
        ],
      },
      {
        instrument: {
          id: '00000000-0000-7000-8000-0000000000b2',
          name: 'Action A',
          isin: null,
          ticker: 'A',
          status: 'ACTIVE',
          quantityPrecision: 6,
        },
        instrumentType: 'EQUITY',
        quantity: '20',
        value: '1000.00',
        costBasis: null,
        unrealizedGain: null,
        performance: null,
        averageUnitCost: null,
        reason: 'UNKNOWN_LOT_COST',
        lastPrice: { price: '50.0000', priceDate: '2026-11-03', priceQuality: 'CURRENT' },
        share: { ratio: '0.398406', percentDisplay: '39.84', reason: null },
        accounts: [
          {
            accountId: CTO,
            label: 'CTO',
            quantity: '20',
            value: '1000.00',
            costBasis: null,
            unrealizedGain: null,
            performance: null,
            valuedOn: '2026-11-03',
            priceDate: '2026-11-03',
            priceQuality: 'CURRENT',
          },
        ],
      },
    ],
    allocation: {
      byInstrument: [
        { key: 'W', label: 'MSCI World', value: '1510.00', share: '0.601594' },
        { key: 'A', label: 'Action A', value: '1000.00', share: '0.398406' },
      ],
      byAssetClass: [{ key: 'EQUITY', label: 'EQUITY', value: '2510.00', share: '1' }],
      byRegion: [
        { key: 'WORLD', label: 'WORLD', value: '1510.00', share: '0.601594' },
        { key: 'UNSPECIFIED', label: 'UNSPECIFIED', value: '1000.00', share: '0.398406' },
      ],
    },
    unsplitAccounts: [],
    truncated: false,
    ...overrides,
  };
}

export const unsplitAccount = {
  accountId: TOTAL_ACCOUNT,
  label: 'Assurance vie',
  reason: 'TOTAL_VALUATION' as const,
  securitiesValue: '800.00',
};
