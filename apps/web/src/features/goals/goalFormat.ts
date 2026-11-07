import type { GoalValueType } from '@cadran/api-client';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

/**
 * A figure of a goal status: a target rate is a ratio shown as a percentage, every other value type
 * compares amounts in the goal currency.
 */
export function formatGoalFigure(
  value: string,
  valueType: GoalValueType | null,
  assetCode: string,
  locale: string,
): string {
  return valueType === 'RATE'
    ? formatRatioPercentage(value, locale)
    : formatAmount(value, assetCode, locale);
}
