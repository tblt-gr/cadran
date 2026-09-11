import type { CategoryType } from '@cadran/api-client';
import { isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';

/**
 * Whether a category of `type` would be refused on a transaction of `amount`: an
 * expense category needs an outflow, an income category an inflow. An unknown type
 * or an amount that is not an exact non-zero decimal yet contradicts nothing.
 */
export function categoryContradictsAmount(type: CategoryType | null, amount: string): boolean {
  if (type === null || !isCanonicalDecimal(amount) || isZeroDecimal(amount)) {
    return false;
  }

  return type !== (amount.startsWith('-') ? 'EXPENSE' : 'INCOME');
}
