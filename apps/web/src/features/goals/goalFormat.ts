import type { GoalEvaluationFigure } from '@cadran/api-client';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

/**
 * A figure of a goal evaluation: an amount in the goal asset, or a ratio shown as a percentage.
 * Null when the figure or its value is absent, never a zero by default.
 */
export function formatEvaluationFigure(
  figure: GoalEvaluationFigure | null,
  locale: string,
): string | null {
  if (figure === null) return null;
  if ('ratio' in figure) {
    return figure.ratio === null ? null : formatRatioPercentage(figure.ratio, locale);
  }
  return figure.value === null ? null : formatAmount(figure.value, figure.assetCode, locale);
}
