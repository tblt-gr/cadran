import type { GoalEvaluation } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { compareDecimals, formatAmount, isCanonicalDecimal } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';

function assetOf(evaluation: GoalEvaluation): string | null {
  for (const figure of [evaluation.target, evaluation.achieved]) {
    if (figure !== null && 'assetCode' in figure) return figure.assetCode;
  }
  return null;
}

function unsigned(value: string): string {
  return value.startsWith('-') ? value.slice(1) : value;
}

/** An amount of the goal asset, or null when the figure or the asset is absent. */
function amount(value: string | null, evaluation: GoalEvaluation, locale: string): string | null {
  const asset = assetOf(evaluation);
  return value === null || asset === null || !isCanonicalDecimal(value)
    ? null
    : formatAmount(value, asset, locale);
}

function percent(value: string | null, locale: string): string | null {
  return value === null || !isCanonicalDecimal(value) ? null : formatRatioPercentage(value, locale);
}

/** `Dépassement de 50,00 €`, `Manque 4 %` or `Marge de 10,00 €`; null when the gap is not calculable. */
export function gapText(evaluation: GoalEvaluation, t: TFunction, locale: string): string | null {
  const gap = evaluation.gap;
  if (gap === null || !isCanonicalDecimal(gap)) return null;
  const figure = evaluation.target ?? evaluation.achieved;
  const magnitude = unsigned(gap);
  const shown =
    figure !== null && 'ratio' in figure
      ? percent(magnitude, locale)
      : amount(magnitude, evaluation, locale);
  if (shown === null) return null;
  // The server signs the gap so that a negative one is against the goal.
  if (compareDecimals(gap, '0') >= 0) return t('goals.evaluation.margin', { amount: shown });
  return t(
    evaluation.direction === 'CEILING' ? 'goals.evaluation.over' : 'goals.evaluation.short',
    {
      amount: shown,
    },
  );
}

/** The formula text of the server key, its operands formatted from the server figures. */
export function formulaText(evaluation: GoalEvaluation, t: TFunction, locale: string): string {
  const { operands, key } = evaluation.formula;
  const dash = t('goals.noValue');
  return t(key, {
    target: formatEvaluationFigure(evaluation.target, locale) ?? dash,
    achieved: formatEvaluationFigure(evaluation.achieved, locale) ?? dash,
    income: amount(operands.income, evaluation, locale) ?? dash,
    ratio: percent(operands.ratio, locale) ?? dash,
  });
}

/** The server margin, with ratios expressed as percentage points. */
export function toleranceText(
  evaluation: GoalEvaluation,
  t: TFunction,
  locale: string,
): string | null {
  const figure = evaluation.tolerance;
  if (figure == null) return null;
  const shown = formatEvaluationFigure(figure, locale);
  if (shown === null) return null;
  return t('goals.evaluation.tolerance', {
    amount:
      'ratio' in figure
        ? t('goals.tolerancePointValue', { value: shown.replace(/\s*%$/, '') })
        : shown,
  });
}
