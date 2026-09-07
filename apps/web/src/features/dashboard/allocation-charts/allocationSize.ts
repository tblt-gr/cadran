import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';

/** Keeps the relative area of every positive allocation, including shares above 100%. */
export function allocationSize(percent: string): number | null {
  if (compareDecimals(percent, '0') <= 0) {
    return null;
  }

  return decimalRatioForGeometry(percent, '100');
}
