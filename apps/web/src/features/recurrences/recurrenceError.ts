import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type RecurrenceErrorKind =
  'archived' | 'duplicate' | 'forbidden' | 'invalid' | 'network' | 'notFound' | 'stale';

/**
 * A refused recurrence request, classified by its RFC 9457 problem type rather than by
 * status alone: a stale version and an archived recurrence are both `409` and ask the user
 * for opposite things (reload, or restore before editing).
 */
export class RecurrenceRequestError extends Error {
  readonly status: number;
  readonly kind: RecurrenceErrorKind;
  readonly detail: string | undefined;

  constructor(status: number, problem: Problem | undefined) {
    super(`Recurrence request failed with status ${status}.`);
    this.status = status;
    this.kind = classify(status, problem?.type);
    this.detail = problem?.detail;
  }
}

const PROBLEM_KINDS: Record<string, RecurrenceErrorKind> = {
  '/problems/recurrences.archived': 'archived',
  '/problems/recurrences.duplicate': 'duplicate',
  '/problems/recurrences.not_found': 'notFound',
  '/problems/recurrences.forbidden': 'forbidden',
};

/** 401 and 403 both mean the caller may not touch this recurrence: `forbidden`. */
function classify(status: number, problemType: string | undefined): RecurrenceErrorKind {
  const kind = classifyProblem(status, problemType, PROBLEM_KINDS);
  if (kind === 'unauthorized') return 'forbidden';

  // A concurrent write has no message of its own here: reload and retry.
  return kind === 'conflict' ? 'network' : kind;
}

export function recurrenceRequestError(result: {
  error?: unknown;
  response?: Response;
}): RecurrenceRequestError {
  const problem = result.error as Problem | undefined;

  return new RecurrenceRequestError(result.response?.status ?? 0, problem);
}

export function recurrenceErrorKind(error: unknown, isError: boolean): RecurrenceErrorKind | null {
  if (error instanceof RecurrenceRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}
