import type { GoalEvaluation, GoalPeriodEvaluation } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { goalTone, type GoalTone } from '@/features/goals/goal-panel/goalPanelModel';

// The worst reading leads: a missed goal must not hide behind a met one on the same figure.
const TONE_ORDER: Record<GoalTone, number> = { negative: 0, positive: 1, neutral: 2, done: 3 };

/**
 * The evaluation items of one period that carry the figure of a column: matched on the period
 * and the column key only, whatever their status. Worst tone first, then by label.
 */
export function marksFor(
  periods: readonly GoalPeriodEvaluation[] | undefined,
  period: string,
  columnKey: string,
): GoalEvaluation[] {
  if (periods === undefined) return [];
  return periods
    .filter((candidate) => candidate.period === period)
    .flatMap((candidate) => candidate.items)
    .filter((item) => item.columnKey === columnKey)
    .sort(
      (a, b) =>
        TONE_ORDER[goalTone(a)] - TONE_ORDER[goalTone(b)] || a.label.localeCompare(b.label, 'fr'),
    );
}

/**
 * The tone of a figure that carries several goals: the one of its first (worst) item. In a report
 * an achieved goal is green like a goal on target: only the panel lets it recede.
 */
export function marksTone(items: readonly GoalEvaluation[]): Exclude<GoalTone, 'done'> | null {
  const first = items[0];
  if (first === undefined) return null;
  const tone = goalTone(first);
  return tone === 'done' ? 'positive' : tone;
}

/** The status word of an evaluation, shared with the goals panel badge. */
export function statusText(evaluation: GoalEvaluation, t: TFunction): string {
  if (evaluation.status === 'MISSED') {
    return t(
      evaluation.direction === 'CEILING'
        ? 'goals.panel.status.MISSED_CEILING'
        : 'goals.panel.status.MISSED_MINIMUM',
    );
  }
  return t(`goals.panel.status.${evaluation.status}`);
}

/**
 * The value to reach or not exceed (`800,00 €`, `10,00 %`); null for a share of income, which has
 * no figure of its own (its explanation says which share of which income), and without target.
 */
export function targetValue(evaluation: GoalEvaluation, locale: string): string | null {
  if (evaluation.valueType === 'INCOME_SHARE' || evaluation.valueType === 'ROLLING_INCOME_SHARE') {
    return null;
  }
  return formatEvaluationFigure(evaluation.target, locale);
}
