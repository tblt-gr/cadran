import type {
  PortfolioPriceQuality,
  PortfolioValuationDetail,
  PortfolioValuationFlag,
} from '@cadran/api-client';
import { lazyLabels } from '@/features/portfolio/lazyLabels';

const prefix = 'portfolio.valuations';
const details = ['TOTAL', 'POSITIONS'] as const;
const flags = [
  'STALE_PRICES',
  'CASH_DIFFERENCE',
  'OBSERVED_TOTAL_DIFFERENCE',
  'POSITIONS_CHANGED',
  'NEGATIVE_SECURITIES_VALUE',
] as const;
const qualities = ['CURRENT', 'STALE', 'MISSING', 'NOT_DETAILED'] as const;

export const flagLabel: Record<PortfolioValuationFlag, string> = lazyLabels(
  flags,
  `${prefix}.flags`,
);

export const flagSentence: Record<PortfolioValuationFlag, string> = lazyLabels(
  flags,
  `${prefix}.flagSentences`,
);

/** Price quality of a valuation summary, in the table's words. */
export const summaryQualityLabel: Record<PortfolioPriceQuality, string | null> = lazyLabels<
  PortfolioPriceQuality,
  string | null
>(qualities, `${prefix}.summaryQuality`, ['NOT_DETAILED']);

export const lineQualityLabel: Record<PortfolioPriceQuality, string> = lazyLabels(
  qualities,
  `${prefix}.lineQuality`,
);

/** How a valuation was entered: the total typed from a statement, or computed from its lines. */
export const entryModeLabel: Record<PortfolioValuationDetail, string> = lazyLabels(
  details,
  `${prefix}.entryMode`,
);
