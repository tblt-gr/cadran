import { formatDecimal } from './decimal';

/**
 * Presents the API's decimal ratio in percent units without rounding or binary
 * floating point: a stored `0.30` is visibly `30 %`.
 */
export function formatRatioPercentage(ratio: string, locale: string): string {
  const [whole, fraction = ''] = ratio.split('.');
  const shifted =
    fraction.length <= 2
      ? `${whole}${fraction.padEnd(2, '0')}`
      : `${whole}${fraction.slice(0, 2)}.${fraction.slice(2)}`;
  const normalized = shifted.replace(/^0+(?=\d)/, '');

  return `${formatDecimal(normalized, locale)} %`;
}
