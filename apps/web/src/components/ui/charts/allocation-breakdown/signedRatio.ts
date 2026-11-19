import { compareDecimals, decimalRatioForGeometry } from '@/lib/decimal';

/** A share is negative when the slice subtracts from the total: the donut cannot draw it. */
export function isNegativeRatio(ratio: string | null): boolean {
  return ratio !== null && compareDecimals(ratio, '0') < 0;
}

/**
 * Donut geometry of a share. Negative and zero shares are left out; the donut proportions are
 * relative, so the positive slices are renormalised by the chart itself. A share above 100 % is
 * capped at 100 % for the drawing only (the table keeps the server value), which is the simplest
 * way to keep a slice drawable without ever leaving exact decimals for a figure.
 */
export function geometrySize(ratio: string | null): number | null {
  if (ratio === null || compareDecimals(ratio, '0') <= 0) {
    return null;
  }

  return decimalRatioForGeometry(compareDecimals(ratio, '1') > 0 ? '1' : ratio, '1');
}
