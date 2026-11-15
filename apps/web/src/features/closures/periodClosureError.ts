import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type PeriodClosureErrorKind =
  'blocked' | 'conflict' | 'forbidden' | 'invalid' | 'network' | 'stale';

/**
 * A refused closure request, classified by its RFC 9457 problem type: a stale
 * version, a blocked closing and an already-closed month are all 409 and ask
 * the user for different things.
 */
export class PeriodClosureRequestError extends Error {
  readonly status: number;
  readonly kind: PeriodClosureErrorKind;

  constructor(status: number, problem: Problem | undefined) {
    super(`Period closure request failed with status ${status}.`);
    this.status = status;
    this.kind = classify(status, problem?.type);
  }
}

const PROBLEM_KINDS: Record<string, PeriodClosureErrorKind> = {
  '/problems/period-closing-blocked': 'blocked',
  '/problems/period-closure-conflict': 'conflict',
};

/** 401 and 403 both mean the caller may not act on this period: `forbidden`. */
function classify(status: number, problemType: string | undefined): PeriodClosureErrorKind {
  const kind = classifyProblem(status, problemType, PROBLEM_KINDS);

  return kind === 'unauthorized' ? 'forbidden' : kind;
}

export function periodClosureRequestError(result: {
  error?: unknown;
  response?: Response;
}): PeriodClosureRequestError {
  return new PeriodClosureRequestError(
    result.response?.status ?? 0,
    result.error as Problem | undefined,
  );
}

export function periodClosureErrorKind(error: unknown): PeriodClosureErrorKind {
  return error instanceof PeriodClosureRequestError ? error.kind : 'network';
}
