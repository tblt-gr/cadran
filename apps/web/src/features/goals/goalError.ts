import type { GoalProblem } from '@cadran/api-client';

export type GoalErrorKind =
  | 'conflict'
  | 'correction_span_exceeded'
  | 'correction_reason_required'
  | 'emergency_fund_scope_too_large'
  | 'invalid_emergency_fund_category'
  | 'invalid'
  | 'invalid_goal_schedule'
  | 'invalid_effective_month'
  | 'invalid_schedule_preview'
  | 'linear_schedule_impossible'
  | 'network'
  | 'schedule_negative_part'
  | 'schedule_part_count'
  | 'schedule_precision_exceeded'
  | 'schedule_sum_mismatch'
  | 'unauthorized';

export interface GoalScheduleMismatch {
  expected: string;
  actual: string;
  difference: string;
}

/** Problem `code` values mapped straight to a display kind, ranked above the HTTP status. */
const CODE_KINDS: Record<string, GoalErrorKind> = {
  correction_reason_required: 'correction_reason_required',
  correction_span_exceeded: 'correction_span_exceeded',
  emergency_fund_scope_too_large: 'emergency_fund_scope_too_large',
  invalid_emergency_fund_category: 'invalid_emergency_fund_category',
  invalid_goal_schedule: 'invalid_goal_schedule',
  invalid_effective_month: 'invalid_effective_month',
  invalid_schedule_preview: 'invalid_schedule_preview',
  linear_schedule_impossible: 'linear_schedule_impossible',
  schedule_negative_part: 'schedule_negative_part',
  schedule_part_count: 'schedule_part_count',
  schedule_precision_exceeded: 'schedule_precision_exceeded',
  schedule_sum_mismatch: 'schedule_sum_mismatch',
};

/** A refused goal request, classified by problem code before status. */
export class GoalRequestError extends Error {
  readonly kind: GoalErrorKind;
  readonly mismatch: GoalScheduleMismatch | null;

  constructor(status: number, problem?: GoalProblem) {
    super(`Goal request failed with status ${status}.`);
    const byCode = problem?.code === undefined ? undefined : CODE_KINDS[problem.code];
    this.kind =
      byCode ??
      (status === 401 || status === 403
        ? 'unauthorized'
        : status === 409
          ? 'conflict'
          : status === 400 || status === 422
            ? 'invalid'
            : 'network');
    this.mismatch =
      problem?.expected !== undefined &&
      problem.actual !== undefined &&
      problem.difference !== undefined
        ? { expected: problem.expected, actual: problem.actual, difference: problem.difference }
        : null;
  }
}

export function goalRequestError(result: {
  error?: unknown;
  response?: Response;
}): GoalRequestError {
  return new GoalRequestError(
    result.response?.status ?? 0,
    result.error as GoalProblem | undefined,
  );
}

export function goalErrorKind(error: unknown, failed: boolean): GoalErrorKind | null {
  return error instanceof GoalRequestError ? error.kind : failed ? 'network' : null;
}

export function goalErrorMismatch(error: unknown): GoalScheduleMismatch | null {
  return error instanceof GoalRequestError ? error.mismatch : null;
}
