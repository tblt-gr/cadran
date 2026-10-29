import { ceilDecimal, formatAmount, formatDecimal } from '@/lib/decimal';

/** An amount in its own asset; without a known asset the bare figure is shown, never a guessed currency. */
export function formatFigure(value: string, assetCode: string | null, locale: string): string {
  return assetCode === null ? formatDecimal(value, locale) : formatAmount(value, assetCode, locale);
}

/**
 * A reference amount rounded up to the whole unit ("1056.88" reads "1 057 €"): the average and the
 * required amount are estimates, so cents would only suggest a precision they do not have.
 */
export function formatCeiledFigure(
  value: string,
  assetCode: string | null,
  locale: string,
): string {
  const whole = ceilDecimal(value);
  if (assetCode === null) return formatDecimal(whole, locale);
  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: assetCode,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(BigInt(whole));
  } catch {
    // An asset the platform does not know as a currency keeps its code next to the grouped figure.
    return `${formatDecimal(whole, locale)} ${assetCode}`;
  }
}
