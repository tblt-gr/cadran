import { formatAmount, formatDecimal } from '@/lib/decimal';

/** An amount in its own asset; without a known asset the bare figure is shown, never a guessed currency. */
export function formatFigure(value: string, assetCode: string | null, locale: string): string {
  return assetCode === null ? formatDecimal(value, locale) : formatAmount(value, assetCode, locale);
}
