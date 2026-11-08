import type { GoalValueType } from '@cadran/api-client';
import { compareDecimals, isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { percentageToRatio, ratioToPercentage } from './goalDecimal';

/** Input conversion only: bounds and status are also enforced by the server. */
export function toleranceInput(value: string, type: GoalValueType): string | null {
  if (value === '') return '0';
  return type === 'AMOUNT'
    ? isCanonicalUnsignedDecimal(value)
      ? value
      : null
    : percentageToRatio(value);
}

export function validTolerance(value: string, target: string, type: GoalValueType): boolean {
  if (value === '') return true;
  const margin = toleranceInput(value, type);
  const bound = type === 'AMOUNT' ? target : percentageToRatio(target);
  return (
    margin !== null &&
    /^\d{1,26}(?:\.\d{1,8})?$/.test(margin) &&
    bound !== null &&
    isCanonicalUnsignedDecimal(bound) &&
    compareDecimals(margin, bound) <= 0
  );
}

export function displayTolerance(value: string, type: GoalValueType): string {
  if (value === '0') return '';
  return type === 'AMOUNT' ? value : (ratioToPercentage(value) ?? '');
}
