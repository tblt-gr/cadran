import { formatDecimal } from './decimal';

/**
 * Presents the API's decimal ratio in percent units without rounding or binary
 * floating point: a stored `0.30` is visibly `30 %`.
 */
export function formatRatioPercentage(ratio: string, locale: string): string {
  return `${formatDecimal(ratioToPercent(ratio), locale)} %`;
}

/** Moves the decimal point two places by string surgery: `0.30` becomes `30`. */
export function ratioToPercent(ratio: string): string {
  const [whole, fraction = ''] = ratio.split('.');
  const shifted =
    fraction.length <= 2
      ? `${whole}${fraction.padEnd(2, '0')}`
      : `${whole}${fraction.slice(0, 2)}.${fraction.slice(2)}`;

  return shifted.replace(/^0+(?=\d)/, '');
}
