import type {
  PortfolioPriceQuality,
  PortfolioValuationDetail,
  PortfolioValuationFlag,
} from '@cadran/api-client';

export const detailLabel: Record<PortfolioValuationDetail, string> = {
  TOTAL: 'Total',
  POSITIONS: 'Par ligne',
};

export const flagLabel: Record<PortfolioValuationFlag, string> = {
  STALE_PRICES: 'Prix anciens',
  CASH_DIFFERENCE: 'Écart d’espèces',
  OBSERVED_TOTAL_DIFFERENCE: 'Écart avec le relevé',
  POSITIONS_CHANGED: 'Positions modifiées depuis',
  NEGATIVE_SECURITIES_VALUE: 'Titres négatifs',
};

export const flagSentence: Record<PortfolioValuationFlag, string> = {
  STALE_PRICES: 'Au moins un prix date de plus de 5 jours avant la valorisation.',
  CASH_DIFFERENCE: 'Les espèces du relevé diffèrent des espèces calculées.',
  OBSERVED_TOTAL_DIFFERENCE:
    'Le total du relevé s’écarte du total calculé au-delà de la tolérance.',
  POSITIONS_CHANGED:
    'Des opérations ont été enregistrées ou annulées depuis, à cette date ou avant : les positions ne correspondent plus.',
  NEGATIVE_SECURITIES_VALUE:
    'Le total est inférieur aux espèces : la valeur des titres est négative.',
};

/** Price quality of a valuation summary, in the table's words. */
export const summaryQualityLabel: Record<PortfolioPriceQuality, string | null> = {
  CURRENT: 'Actuels',
  STALE: 'Anciens',
  MISSING: 'Manquants',
  NOT_DETAILED: null,
};

export const lineQualityLabel: Record<PortfolioPriceQuality, string> = {
  CURRENT: 'Actuel',
  STALE: 'Ancien',
  MISSING: 'Manquant',
  NOT_DETAILED: 'Non détaillé',
};
