import type { PortfolioPerformanceReason } from '@cadran/api-client';
import type { ParseKeys, TFunction } from 'i18next';
import { flagSentence } from '@/features/portfolio/valuations/valuationLabels';
import { formatAmount, formatCalendarDay, formatCalendarNumericDay } from '@/lib/decimal';

/** What a reason needs to name the account asset, the account and the figures it quotes. */
export interface ReasonSubject {
  accountId: string;
  assetCode: string;
  /** Gain decomposition residual, quoted by `DECOMPOSITION_RESIDUAL`. */
  residual: string | null;
}

export interface ReasonContext extends ReasonSubject {
  t: TFunction;
  /** The active language, for dates and amounts. */
  locale: string;
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
  (key: string): Describe =>
  (_dates, { t }) =>
    t(`portfolio.performance.reasons.${key}` as ParseKeys);
const datesList =
  (key: string): Describe =>
  (dates, { t }) =>
    t(`portfolio.performance.reasons.${key}` as ParseKeys, { dates: list(dates) });
const firstDate =
  (key: string): Describe =>
  (dates, { t }) =>
    t(`portfolio.performance.reasons.${key}` as ParseKeys, { date: first(dates) });
const takeoverDate =
  (key: string): Describe =>
  (dates, { t, locale }) =>
    t(`portfolio.performance.reasons.${key}` as ParseKeys, {
      date: formatCalendarDay(dates[0] ?? '', locale),
    });
const tooMuch = fixed('tooMuch');
const weakCapital = fixed('weakCapital');

const sentences: Record<string, Describe> = {
  NO_START_VALUATION: fixed('NO_START_VALUATION'),
  NO_END_VALUATION: fixed('NO_END_VALUATION'),
  START_VALUATION_PARTIAL: firstDate('VALUATION_PARTIAL'),
  END_VALUATION_PARTIAL: firstDate('VALUATION_PARTIAL'),
  UNVALUED_SECURITY_TRANSFER: fixed('UNVALUED_SECURITY_TRANSFER'),
  NO_INVESTED_CAPITAL: fixed('NO_INVESTED_CAPITAL'),
  NON_POSITIVE_DENOMINATOR: weakCapital,
  DENOMINATOR_TOO_SMALL: weakCapital,
  MONTH_NOT_CALCULABLE: datesList('MONTH_NOT_CALCULABLE'),
  NON_POSITIVE_GROWTH_FACTOR: datesList('NON_POSITIVE_GROWTH_FACTOR'),
  TOO_MANY_PERIODS: tooMuch,
  TOO_MANY_SUBPERIODS: tooMuch,
  TOO_MANY_FLOWS: tooMuch,
  MISSING_FLOW_VALUATION: datesList('MISSING_FLOW_VALUATION'),
  STALE_FLOW_VALUATION: datesList('STALE_FLOW_VALUATION'),
  ZERO_START_VALUE: fixed('ZERO_START_VALUE'),
  NO_SIGN_CHANGE: fixed('NO_SIGN_CHANGE'),
  NO_ROOT_IN_RANGE: fixed('NO_ROOT_IN_RANGE'),
  MULTIPLE_ROOTS: fixed('MULTIPLE_ROOTS'),
  NO_CONVERGENCE: fixed('NO_CONVERGENCE'),
  UNKNOWN_LOT_COST: fixed('UNKNOWN_LOT_COST'),
  ...Object.fromEntries(Object.entries(flagSentence).map(([code, text]) => [code, () => text])),
  BEFORE_TAKEOVER: takeoverDate('BEFORE_TAKEOVER'),
  PERIOD_STARTS_AT_TAKEOVER: takeoverDate('PERIOD_STARTS_AT_TAKEOVER'),
  PERIOD_IN_PROGRESS: firstDate('PERIOD_IN_PROGRESS'),
  DECOMPOSITION_RESIDUAL: (_dates, { residual, assetCode, t, locale }) =>
    t('portfolio.performance.reasons.DECOMPOSITION_RESIDUAL', {
      amount:
        residual === null
          ? t('portfolio.performance.reasons.unknownAmount')
          : formatAmount(residual, assetCode, locale),
    }),
};

const actionLabelKeys = {
  recordValuation: 'portfolio.performance.actions.recordValuation',
  completeValuation: 'portfolio.performance.actions.completeValuation',
  checkPositions: 'portfolio.performance.actions.checkPositions',
} as const;
const actions: Record<
  string,
  { label: keyof typeof actionLabelKeys; tab: 'valuations' | 'positions' }
> = {
  NO_START_VALUATION: { label: 'recordValuation', tab: 'valuations' },
  NO_END_VALUATION: { label: 'recordValuation', tab: 'valuations' },
  MISSING_FLOW_VALUATION: { label: 'recordValuation', tab: 'valuations' },
  START_VALUATION_PARTIAL: { label: 'completeValuation', tab: 'valuations' },
  END_VALUATION_PARTIAL: { label: 'completeValuation', tab: 'valuations' },
  STALE_FLOW_VALUATION: { label: 'completeValuation', tab: 'valuations' },
  STALE_PRICES: { label: 'completeValuation', tab: 'valuations' },
  UNKNOWN_LOT_COST: { label: 'checkPositions', tab: 'positions' },
};

/** One catalogue entry per API reason code: its sentence and the action that can fix it. */
export function describeReason(
  reason: PortfolioPerformanceReason,
  context: ReasonContext,
): DescribedReason {
  const sentence = sentences[reason.code];
  const action = actions[reason.code];
  return {
    sentence: sentence
      ? sentence(reason.dates, context)
      : context.t('portfolio.performance.reasons.unrecognized', { code: String(reason.code) }),
    action: action
      ? {
          label: context.t(actionLabelKeys[action.label]),
          href: `/portfolios/${context.accountId}?tab=${action.tab}`,
        }
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
    case 'STALE_FLOW_VALUATION':
    case 'MONTH_NOT_CALCULABLE':
    case 'NON_POSITIVE_GROWTH_FACTOR':
      return context.t(`portfolio.performance.reasonSummaries.${reason.code}`);
    default:
      return describeReason(reason, context).sentence;
  }
}
