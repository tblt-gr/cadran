import type { PortfolioPerformanceReason } from '@cadran/api-client';
import { flagSentence } from '@/features/portfolio/valuations/valuationLabels';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';

/** What a reason needs to name the account asset, the account and the figures it quotes. */
export interface ReasonContext {
  accountId: string;
  assetCode: string;
  /** Gain decomposition residual, quoted by `DECOMPOSITION_RESIDUAL`. */
  residual: string | null;
}

interface ReasonAction {
  label: string;
  href: string;
}

export interface DescribedReason {
  sentence: string;
  action: ReasonAction | null;
}

type Describe = (dates: string[], context: ReasonContext) => string;

const day = (date: string) =>
  /^\d{4}-\d{2}$/.test(date)
    ? `${date.slice(5)}/${date.slice(0, 4)}`
    : formatCalendarNumericDay(date);
const list = (dates: string[]) => dates.map(day).join(', ');
const first = (dates: string[]) => (dates[0] ? day(dates[0]) : '');
const fixed =
  (sentence: string): Describe =>
  () =>
    sentence;
const tooMuch = fixed('Trop de données pour ce calcul ; choisissez une période plus courte.');
const weakCapital = fixed('Capital moyen trop faible pour un taux significatif.');

const sentences: Record<string, Describe> = {
  NO_START_VALUATION: fixed('Aucune valorisation complète à la fin du mois précédent.'),
  NO_END_VALUATION: fixed('Aucune valorisation complète dans la période.'),
  START_VALUATION_PARTIAL: (dates) => `La valorisation du ${first(dates)} est partielle.`,
  END_VALUATION_PARTIAL: (dates) => `La valorisation du ${first(dates)} est partielle.`,
  UNVALUED_SECURITY_TRANSFER: fixed('Un transfert de titres sans valeur empêche le calcul.'),
  NO_INVESTED_CAPITAL: fixed('Aucun capital investi sur la période.'),
  NON_POSITIVE_DENOMINATOR: weakCapital,
  DENOMINATOR_TOO_SMALL: weakCapital,
  MONTH_NOT_CALCULABLE: (dates) => `Mois non calculables : ${list(dates)}.`,
  NON_POSITIVE_GROWTH_FACTOR: (dates) =>
    `Rendement mensuel inférieur ou égal à -100 % : le chaînage n'est pas publié (${list(dates)}).`,
  TOO_MANY_PERIODS: tooMuch,
  TOO_MANY_SUBPERIODS: tooMuch,
  TOO_MANY_FLOWS: tooMuch,
  MISSING_FLOW_VALUATION: (dates) => `Valorisation manquante le jour d'un flux : ${list(dates)}.`,
  STALE_FLOW_VALUATION: (dates) => `Prix anciens le jour d'un flux : ${list(dates)}.`,
  ZERO_START_VALUE: fixed("Valeur nulle au début d'une sous-période."),
  NO_SIGN_CHANGE: fixed('Il faut au moins un versement et une valeur ou un retrait.'),
  NO_ROOT_IN_RANGE: fixed('Aucun taux entre -99,9 % et +3 678 %.'),
  MULTIPLE_ROOTS: fixed("Plusieurs taux possibles : aucun n'est retenu."),
  NO_CONVERGENCE: fixed("Le calcul n'a pas convergé."),
  UNKNOWN_LOT_COST: fixed("Coût d'acquisition inconnu : décomposition indisponible."),
  ...Object.fromEntries(Object.entries(flagSentence).map(([code, text]) => [code, fixed(text)])),
  PERIOD_IN_PROGRESS: (dates) => `Période en cours, calculée jusqu'au ${first(dates)}.`,
  DECOMPOSITION_RESIDUAL: (_dates, { residual, assetCode }) =>
    `Écart de décomposition de ${residual === null ? 'montant inconnu' : formatAmount(residual, assetCode, 'fr')}.`,
};

const recordValuation = 'Enregistrer une valorisation';
const completeValuation = 'Compléter la valorisation';
const actionLabels: Record<string, { label: string; tab: 'valuations' | 'positions' }> = {
  NO_START_VALUATION: { label: recordValuation, tab: 'valuations' },
  NO_END_VALUATION: { label: recordValuation, tab: 'valuations' },
  MISSING_FLOW_VALUATION: { label: recordValuation, tab: 'valuations' },
  START_VALUATION_PARTIAL: { label: completeValuation, tab: 'valuations' },
  END_VALUATION_PARTIAL: { label: completeValuation, tab: 'valuations' },
  STALE_FLOW_VALUATION: { label: completeValuation, tab: 'valuations' },
  STALE_PRICES: { label: completeValuation, tab: 'valuations' },
  UNKNOWN_LOT_COST: { label: 'Vérifier les positions', tab: 'positions' },
};

/** One catalogue entry per API reason code: its sentence and the action that can fix it. */
export function describeReason(
  reason: PortfolioPerformanceReason,
  context: ReasonContext,
): DescribedReason {
  const sentence = sentences[reason.code];
  const action = actionLabels[reason.code];
  return {
    sentence: sentence
      ? sentence(reason.dates, context)
      : `Motif non reconnu (${String(reason.code)})`,
    action: action
      ? { label: action.label, href: `/portfolios/${context.accountId}?tab=${action.tab}` }
      : null,
  };
}

export const listedDateLimit = 3;

/** Dates of a reason, in the same order and format as its full sentence. */
export function formatReasonDates(dates: string[]): string {
  return list(dates);
}

/** Overview copy stays short; exact dates remain in the figure's explanation. */
export function summarizeReason(
  reason: PortfolioPerformanceReason,
  context: ReasonContext,
): string {
  switch (reason.code) {
    case 'MISSING_FLOW_VALUATION':
      return 'Relevés manquants aux dates de versement ou retrait.';
    case 'STALE_FLOW_VALUATION':
      return 'Prix anciens aux dates de versement ou retrait.';
    case 'MONTH_NOT_CALCULABLE':
      return 'Des relevés mensuels manquent pour ce calcul.';
    case 'NON_POSITIVE_GROWTH_FACTOR':
      return 'Un rendement mensuel inférieur ou égal à -100 % empêche le chaînage.';
    default:
      return describeReason(reason, context).sentence;
  }
}
