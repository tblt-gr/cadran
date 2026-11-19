import { compareDecimals } from '@/lib/decimal';

/** A share is signed when it is negative or above 100 %: no donut can represent it. */
export function isSignedRatio(ratio: string | null): boolean {
  return ratio !== null && (compareDecimals(ratio, '0') < 0 || compareDecimals(ratio, '1') > 0);
}
