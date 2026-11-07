import {
  formatAmount,
  formatCalendarDay,
  formatDecimal,
  formatPercentDecimal,
} from '@/lib/decimal';

const LOCALE = 'fr';

/** An amount in its asset, or the bare figure when the accounts do not share one unit. */
export function holdingsMoney(value: string, assetCode: string | null): string {
  return assetCode === null ? formatDecimal(value, LOCALE) : formatAmount(value, assetCode, LOCALE);
}

/** The server rounds the share to two decimals; the browser only appends the unit. */
export function holdingsPercent(percentDisplay: string): string {
  return `${formatPercentDecimal(percentDisplay, LOCALE)} %`;
}

export function holdingsDay(isoDay: string): string {
  return formatCalendarDay(isoDay, LOCALE);
}

/** A holding or total that is only a missing figure is told in words, never as zero. */
export function isNoInvestmentAccount(holdings: {
  quality: string;
  items: readonly unknown[];
  unsplitAccounts: readonly unknown[];
  totals: { reason: string | null };
}): boolean {
  return (
    holdings.quality === 'NOT_CALCULABLE' &&
    holdings.items.length === 0 &&
    holdings.unsplitAccounts.length === 0 &&
    holdings.totals.reason === 'NO_VALUATION'
  );
}

/** A unit price keeps the precision of the instrument, so it is never rounded to cents. */
export function holdingsUnitPrice(value: string, assetCode: string | null): string {
  const figure = formatDecimal(value, LOCALE);
  if (assetCode === null) return figure;
  try {
    const symbol = new Intl.NumberFormat(LOCALE, { style: 'currency', currency: assetCode })
      .formatToParts(0)
      .find((part) => part.type === 'currency')?.value;
    return `${figure} ${symbol ?? assetCode}`;
  } catch {
    return `${figure} ${assetCode}`;
  }
}
