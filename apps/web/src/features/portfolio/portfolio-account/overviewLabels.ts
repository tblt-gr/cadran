import type { TFunction } from 'i18next';

const kpiCodes = [
  'TOTAL_VALUE',
  'UNREALIZED_GAIN',
  'CASH',
  'NET_DEPOSITS',
  'COSTS',
  'RECONCILIATION',
] as const;
export type KpiCode = (typeof kpiCodes)[number];

export function kpiLabel(t: TFunction, code: KpiCode): string {
  return t(`portfolio.account.kpi.${code}`);
}

const flagCodes = [
  'STALE_PRICES',
  'CASH_DIFFERENCE',
  'OBSERVED_TOTAL_DIFFERENCE',
  'POSITIONS_CHANGED',
  'NEGATIVE_SECURITIES_VALUE',
] as const;
const warningCodes = [
  'LINKED_TRANSFER_CHANGED',
  'NON_TRANSFER_TRANSACTION',
  'UNLINKED_TRANSFER_IN',
  'UNLINKED_TRANSFER_OUT',
  'NEGATIVE_CASH',
  'UNKNOWN_LOT_COST',
  'ORDER_DEPENDENT_DAY',
  'PARTIAL_VALUATION',
  'STALE_VALUATION',
  'MISSING_PRICE',
  'UNKNOWN_POSITION_COST',
] as const;
const reasonCodes = [
  'NO_VALUATION',
  'NOT_DETAILED',
  'NON_POSITIVE_TOTAL',
  'COMPLETE',
  'PARTIAL',
  'DERIVED',
  'OBSERVED',
  'RECONCILED',
  'NEEDS_REVIEW',
  'NO_DATA',
  'EXACT',
  'CURRENT',
  'WARNING',
] as const;

function isOneOf<T extends string>(codes: readonly T[], code: string): code is T {
  return (codes as readonly string[]).includes(code);
}

/** The sentence of a reconciliation warning or valuation flag, undefined for an unknown code. */
export function warningLabel(t: TFunction, code: string): string | undefined {
  if (isOneOf(flagCodes, code)) return t(`portfolio.account.flagSentence.${code}`);
  if (isOneOf(warningCodes, code)) return t(`portfolio.account.warning.${code}`);
  return undefined;
}

/** The sentence of a quality, status or "not computable" reason, undefined for an unknown code. */
export function reasonLabel(t: TFunction, code: string): string | undefined {
  if (isOneOf(reasonCodes, code)) return t(`portfolio.account.reason.${code}`);
  return warningLabel(t, code);
}

/** The short name of a valuation flag, as the history chart and table list it. */
export function flagShortLabel(t: TFunction, flag: (typeof flagCodes)[number]): string {
  return t(`portfolio.account.flag.${flag}`);
}
