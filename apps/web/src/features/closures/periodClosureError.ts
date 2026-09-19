import type { Problem } from '@cadran/api-client';

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

function classify(status: number, problemType: string | undefined): PeriodClosureErrorKind {
  if (problemType === '/problems/stale-version') {
    return 'stale';
  }
  if (problemType === '/problems/period-closing-blocked') {
    return 'blocked';
  }
  if (problemType === '/problems/period-closure-conflict') {
    return 'conflict';
  }
  if (status === 401 || status === 403) {
    return 'forbidden';
  }

  return status === 400 || status === 422 ? 'invalid' : 'network';
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
