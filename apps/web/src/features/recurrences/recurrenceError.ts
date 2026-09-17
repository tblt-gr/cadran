import type { Problem } from '@cadran/api-client';

export type RecurrenceErrorKind =
  'archived' | 'forbidden' | 'invalid' | 'network' | 'notFound' | 'stale';

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

function classify(status: number, problemType: string | undefined): RecurrenceErrorKind {
  if (problemType === '/problems/stale-version') {
    return 'stale';
  }
  if (problemType === '/problems/recurrences.archived') {
    return 'archived';
  }
  if (problemType === '/problems/recurrences.not_found') {
    return 'notFound';
  }
  if (problemType === '/problems/recurrences.forbidden' || status === 401 || status === 403) {
    return 'forbidden';
  }

  return status === 400 || status === 422 ? 'invalid' : 'network';
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
