import { isCanonicalUnsignedDecimal } from '@/lib/decimal';

/** Converts a displayed percent to the API's exact ratio by moving digits only. */
export function percentageToRatio(percentage: string): string | null {
  if (!isCanonicalUnsignedDecimal(percentage)) return null;
  const [whole, fraction = ''] = percentage.split('.');
  const digits = `${whole}${fraction}`.replace(/^0+(?=\d)/, '');
  const scale = fraction.length + 2;
  const padded = digits.padStart(scale + 1, '0');
  return `${padded.slice(0, -scale)}.${padded.slice(-scale)}`;
}

/** Converts an exact API ratio back to the displayed percent, the inverse of `percentageToRatio`. */
export function ratioToPercentage(ratio: string): string | null {
  if (!isCanonicalUnsignedDecimal(ratio)) return null;
  const [whole, fraction = ''] = ratio.split('.');
  const shifted = fraction.padEnd(2, '0');
  const digits = `${whole}${shifted.slice(0, 2)}`.replace(/^0+(?=\d)/, '');
  const rest = shifted.slice(2);
  return rest ? `${digits}.${rest}` : digits;
}

export function isValidPositiveRatioPercentage(percentage: string): boolean {
  const ratio = percentageToRatio(percentage);
  if (!ratio || ratio.length - ratio.indexOf('.') - 1 > 8) return false;
  const [whole, fraction] = ratio.split('.');
  return whole === '0' ? /[1-9]/.test(fraction) : whole === '1' && /^0+$/.test(fraction);
}
