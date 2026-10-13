import type {
  PortfolioValuation,
  PortfolioValuationDraft,
  PortfolioValuationPage,
  PortfolioValuationSummary,
} from '@cadran/api-client';

const eur = (value: string) => ({ value, assetCode: 'EUR' });

const etf = {
  id: 'ins-etf',
  name: 'ETF Monde Démo',
  isin: 'IE00B4L5Y983',
  ticker: 'EMDM',
  status: 'ACTIVE' as const,
  quantityPrecision: 4,
  pricePrecision: 4,
};
const industry = {
  id: 'ins-ind',
  name: 'Action Démo Industrie',
  isin: null,
  ticker: null,
  status: 'ACTIVE' as const,
  quantityPrecision: 0,
  pricePrecision: 2,
};

/** The reference POSITIONS valuation: 2314.521 + 156.90 + 1193.28 = 3664.701. */
export function positionsValuationFixture(
  overrides: Partial<PortfolioValuation> = {},
): PortfolioValuation {
  return {
    id: 'val-positions',
    accountId: 'acc-1',
    valuedOn: '2026-03-10',
    detail: 'POSITIONS',
    source: 'MANUAL',
    totalValue: eur('3664.701'),
    cash: eur('1193.28'),
    securitiesValue: eur('2471.421'),
    completeness: 'COMPLETE',
    priceQuality: 'CURRENT',
    flags: [],
    version: 1,
    derivedCash: eur('1193.28'),
    cashSource: 'DERIVED',
    cashDifference: null,
    observedTotal: eur('3664.70'),
    observedTotalDifference: eur('-0.001'),
    tolerance: eur('0.015'),
    note: null,
    supersedesId: null,
    supersededAt: null,
    recordedAt: '2026-03-10T09:00:00+00:00',
    positions: [
      {
        instrument: industry,
        quantity: '3',
        unitPrice: '52.30',
        priceDate: '2026-03-10',
        priceAgeDays: 0,
        priceQuality: 'CURRENT',
        marketValue: eur('156.90'),
        averageCostBasis: eur('150.00'),
      },
      {
        instrument: etf,
        quantity: '10',
        unitPrice: '231.4521',
        priceDate: '2026-03-10',
        priceAgeDays: 0,
        priceQuality: 'CURRENT',
        marketValue: eur('2314.521'),
        averageCostBasis: eur('2300.00'),
      },
    ],
    ...overrides,
  };
}

/** The reference TOTAL valuation: total 3664.70, derived cash 1193.28, securities 2471.42. */
export function totalValuationFixture(
  overrides: Partial<PortfolioValuation> = {},
): PortfolioValuation {
  const base = positionsValuationFixture();
  return {
    ...base,
    id: 'val-total',
    valuedOn: '2026-02-28',
    detail: 'TOTAL',
    totalValue: eur('3664.70'),
    securitiesValue: eur('2471.42'),
    observedTotal: null,
    observedTotalDifference: null,
    priceQuality: 'NOT_DETAILED',
    positions: base.positions.map((line) => ({
      ...line,
      unitPrice: null,
      priceDate: null,
      priceAgeDays: null,
      priceQuality: 'NOT_DETAILED' as const,
      marketValue: null,
    })),
    ...overrides,
  };
}

function summaryOf(valuation: PortfolioValuation): PortfolioValuationSummary {
  return {
    id: valuation.id,
    accountId: valuation.accountId,
    valuedOn: valuation.valuedOn,
    detail: valuation.detail,
    source: valuation.source,
    totalValue: valuation.totalValue,
    cash: valuation.cash,
    securitiesValue: valuation.securitiesValue,
    completeness: valuation.completeness,
    priceQuality: valuation.priceQuality,
    flags: valuation.flags,
    version: valuation.version,
  };
}

export function valuationPageFixture(
  items: PortfolioValuation[] = [positionsValuationFixture(), totalValuationFixture()],
): PortfolioValuationPage {
  return { items: items.map(summaryOf), page: 1, pageSize: 24, total: items.length };
}

/** A draft with a buy-priced ETF and an industry share last valued on the 3rd. */
export function draftFixture(
  overrides: Partial<PortfolioValuationDraft> = {},
): PortfolioValuationDraft {
  return {
    valuedOn: '2026-03-10',
    derivedCash: eur('1193.28'),
    suggestedDetail: 'TOTAL',
    lastTotal: { value: eur('3500.00'), valuedOn: '2026-02-28' },
    currentValuation: null,
    positions: [
      {
        instrument: industry,
        quantity: '3',
        lastPrice: '52.30',
        lastPriceDate: '2026-03-03',
        lastPriceOrigin: { kind: 'VALUATION', id: 'val-old', date: '2026-03-03' },
      },
      {
        instrument: etf,
        quantity: '10',
        lastPrice: '231.20',
        lastPriceDate: '2026-03-03',
        lastPriceOrigin: { kind: 'OPERATION', id: 'op-1', date: '2026-03-03' },
      },
    ],
    ...overrides,
  };
}
