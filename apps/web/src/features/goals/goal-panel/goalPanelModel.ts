import { compareDecimals, decimalRatio, isCanonicalDecimal } from '@/lib/decimal';
import type {
  GoalEvaluationFigure,
  GoalDirection,
  GoalEvaluation,
  GoalPeriodEvaluation,
  GoalSummary,
} from '@cadran/api-client';

/** A winning evaluation with the goals it hides for the period. */
export interface GoalRowModel {
  evaluation: GoalEvaluation;
  summary: GoalSummary | null;
  replaced: { id: string; label: string }[];
}

function byLabel(a: { label: string }, b: { label: string }): number {
  return a.label.localeCompare(b.label, 'fr');
}

/** Ceilings first, then minimums, then by label. */
function compareEvaluations(
  a: { direction: GoalDirection; label: string },
  b: { direction: GoalDirection; label: string },
): number {
  if (a.direction !== b.direction) return a.direction === 'CEILING' ? -1 : 1;
  return byLabel(a, b);
}

export function indexSummaries(summaries: readonly GoalSummary[]): Map<string, GoalSummary> {
  return new Map(summaries.map((summary) => [summary.id, summary]));
}

/** The rows of one period (month or year), the goals hidden by precedence folded under their winner. */
export function buildRows(
  period: GoalPeriodEvaluation | undefined,
  summaries: ReadonlyMap<string, GoalSummary>,
): GoalRowModel[] {
  if (period === undefined) return [];
  return [...period.items].sort(compareEvaluations).map((evaluation) => ({
    evaluation,
    summary: summaries.get(evaluation.goalId) ?? null,
    replaced: evaluation.overrides.flatMap((id) => {
      const hidden = summaries.get(id);
      return hidden === undefined ? [] : [{ id, label: hidden.label }];
    }),
  }));
}

/** How a goal row reads: `done` recedes, `positive` is green, `negative` red, `neutral` plain. */
export type GoalTone = 'done' | 'positive' | 'negative' | 'neutral';

/**
 * Reads the tone from server data only: the status, the direction, the sign of the gap (signed so
 * that positive is in the goal's favour) and the pace of a yearly goal. A running minimum below its
 * target is already red, although the server keeps it `IN_PROGRESS` until the month is over.
 */
export function goalTone(
  evaluation: Pick<GoalEvaluation, 'status' | 'direction' | 'periodicity' | 'gap' | 'pace'>,
): GoalTone {
  switch (evaluation.status) {
    case 'NOT_CALCULABLE':
      return 'neutral';
    case 'MET':
      return 'done';
    case 'MISSED':
      return 'negative';
    case 'IN_PROGRESS': {
      // Mid-year the full-year gap is misleading: the pace says whether the year is on course.
      if (evaluation.periodicity === 'YEARLY' && evaluation.pace !== null) {
        return evaluation.pace.status === 'BEHIND' || evaluation.pace.status === 'OVER_PACE'
          ? 'negative'
          : 'positive';
      }
      if (evaluation.gap === null) return 'neutral';
      // A ceiling in the red is already MISSED server side, so a negative gap here is a minimum.
      return compareDecimals(evaluation.gap, '0') < 0 ? 'negative' : 'positive';
    }
  }
}

/**
 * Whether a goal is on target at this moment, from server data only: met, or still running with a
 * gap that is not against the goal (a minimum already reached, a ceiling still respected).
 */
export function isOnTarget(evaluation: Pick<GoalEvaluation, 'status' | 'gap'>): boolean {
  if (evaluation.status === 'MET') return true;
  if (evaluation.status !== 'IN_PROGRESS' || evaluation.gap == null) return false;
  return compareDecimals(evaluation.gap, '0') >= 0;
}

function rawFigure(figure: GoalEvaluationFigure | null): string | null {
  if (figure === null) return null;
  return 'ratio' in figure ? figure.ratio : figure.value;
}

/**
 * Fill of the progress bar: achieved over target as an exact decimal, truncated to four places and
 * clamped to 0..1. Geometry only; the status shown always comes from the server. Null when either
 * figure is absent or the target is not positive.
 */
export function goalFillRatio(
  evaluation: Pick<GoalEvaluation, 'status' | 'target' | 'achieved'>,
): string | null {
  if (evaluation.status === 'NOT_CALCULABLE') return null;
  const target = rawFigure(evaluation.target);
  const achieved = rawFigure(evaluation.achieved);
  if (target === null || achieved === null) return null;
  if (!isCanonicalDecimal(target) || !isCanonicalDecimal(achieved)) return null;
  if (compareDecimals(target, '0') <= 0) return null;
  if (compareDecimals(achieved, '0') <= 0) return '0';
  const ratio = decimalRatio(achieved, target, 4);
  return compareDecimals(ratio, '1') > 0 ? '1' : ratio;
}
