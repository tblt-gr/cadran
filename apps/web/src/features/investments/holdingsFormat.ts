import type { TFunction } from 'i18next';
import {
  formatAmount,
  formatCalendarDay,
  formatDecimal,
  formatPercentDecimal,
} from '@/lib/decimal';

/** An amount in its asset, or the bare figure when the accounts do not share one unit. */
export function holdingsMoney(value: string, assetCode: string | null, locale: string): string {
  return assetCode === null ? formatDecimal(value, locale) : formatAmount(value, assetCode, locale);
}

/** The server rounds the share to two decimals; the browser only appends the unit. */
export function holdingsPercent(percentDisplay: string, locale: string): string {
  return `${formatPercentDecimal(percentDisplay, locale)} %`;
}

export function holdingsDay(isoDay: string, locale: string): string {
  return formatCalendarDay(isoDay, locale);
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
export function holdingsUnitPrice(value: string, assetCode: string | null, locale: string): string {
  const figure = formatDecimal(value, locale);
  if (assetCode === null) return figure;
  try {
    const symbol = new Intl.NumberFormat(locale, { style: 'currency', currency: assetCode })
      .formatToParts(0)
      .find((part) => part.type === 'currency')?.value;
    return `${figure} ${symbol ?? assetCode}`;
  } catch {
    return `${figure} ${assetCode}`;
  }
}

/** The `{ISIN ou ticker} · {type}` line under an instrument name. */
export function holdingsInstrumentLine(
  instrument: { isin: string | null; ticker: string | null },
  instrumentType: string,
  t: TFunction,
): string {
  const kind = t(`investments.instruments.types.${instrumentType}`, {
    defaultValue: instrumentType,
  });
  const identifier = instrument.isin ?? instrument.ticker;
  return identifier === null ? kind : `${identifier} · ${kind}`;
}
