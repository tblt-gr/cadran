import type { Holdings, HoldingsAccountSummary } from '@cadran/api-client';

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
      knownCost: null,
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
      byAccount: [
        { key: CTO, label: 'CTO', value: '1510.00', share: '0.601594' },
        { key: PEA, label: 'PEA', value: '1000.00', share: '0.398406' },
      ],
      byAssetClass: [{ key: 'EQUITY', label: 'EQUITY', value: '2510.00', share: '1' }],
      byRegion: [
        { key: 'WORLD', label: 'WORLD', value: '1510.00', share: '0.601594' },
        { key: 'UNSPECIFIED', label: 'UNSPECIFIED', value: '1000.00', share: '0.398406' },
      ],
    },
    accounts: [
      {
        accountId: CTO,
        label: 'CTO',
        detail: 'LINES',
        valuedOn: '2026-11-03',
        securitiesValue: '1510.00',
        costBasis: null,
        unrealizedGain: null,
        performance: null,
        cash: '120.00',
        knownCost: null,
        share: { ratio: '0.601594', percentDisplay: '60.16', reason: null },
        lines: [
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
            priceDate: '2026-11-03',
            priceQuality: 'CURRENT',
          },
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
            quantity: '5',
            value: '510.00',
            costBasis: '480.00',
            unrealizedGain: '30.00',
            performance: '0.0625',
            priceDate: '2026-11-03',
            priceQuality: 'CURRENT',
          },
        ],
      },
      {
        accountId: PEA,
        label: 'PEA',
        detail: 'LINES',
        valuedOn: '2026-10-31',
        securitiesValue: '1000.00',
        costBasis: '900.00',
        unrealizedGain: '100.00',
        performance: '0.111111',
        cash: '50.00',
        knownCost: null,
        share: { ratio: '0.398406', percentDisplay: '39.84', reason: null },
        lines: [
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
            quantity: '10',
            value: '1000.00',
            costBasis: '900.00',
            unrealizedGain: '100.00',
            performance: '0.111111',
            priceDate: '2026-10-31',
            priceQuality: 'CURRENT',
          },
        ],
      },
    ],
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

/** An account valued as a statement total: counted in the securities, no lines. */
export const totalAccountSummary: HoldingsAccountSummary = {
  accountId: TOTAL_ACCOUNT,
  label: 'Assurance vie',
  detail: 'TOTAL',
  valuedOn: '2026-11-01',
  securitiesValue: '800.00',
  costBasis: null,
  unrealizedGain: null,
  performance: null,
  cash: null,
  knownCost: null,
  share: { ratio: null, percentDisplay: null, reason: 'MISSING_VALUE' },
  lines: [],
};

const unsplitSlice = {
  key: 'UNSPLIT',
  label: 'Comptes valorisés en total',
  value: '800.00',
  share: '1',
};

/** Only an account valued as a statement total: no line, but totals and allocation by account. */
export function allTotalHoldings(): Holdings {
  return holdingsFixture({
    items: [],
    warnings: [],
    quality: 'DEGRADED',
    valuedFrom: '2026-11-01',
    valuedTo: '2026-11-01',
    totals: {
      securitiesValue: '800.00',
      costBasis: null,
      unrealizedGain: null,
      performance: null,
      cash: '10.00',
      totalValue: '810.00',
      reason: 'UNKNOWN_LOT_COST',
      knownCost: null,
    },
    allocation: {
      byAccount: [{ key: TOTAL_ACCOUNT, label: 'Assurance vie', value: '800.00', share: '1' }],
      byInstrument: [unsplitSlice],
      byAssetClass: [unsplitSlice],
      byRegion: [unsplitSlice],
    },
    accounts: [totalAccountSummary],
    unsplitAccounts: [unsplitAccount],
  });
}

/** Worked example: the cost of one position is unknown, the other is costed and valued. */
export const knownCostFigures = {
  securitiesValue: '42341.81',
  costBasis: '39887.16',
  unrealizedGain: '2454.65',
  performance: '0.0615',
  excludedCount: 1,
  excludedValue: '1239.00',
};
