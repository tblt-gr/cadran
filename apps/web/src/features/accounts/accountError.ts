import type { Problem } from '@cadran/api-client';

export type AccountErrorKind = 'archived' | 'conflict' | 'invalid' | 'network' | 'stale';

/**
 * A refused account request, classified by its RFC 9457 problem type rather
 * than by its status alone: a taken label and a stale version are both 409 and
 * ask the user for opposite things.
 */
export class AccountRequestError extends Error {
  readonly status: number;
  readonly kind: AccountErrorKind;

  constructor(status: number, problemType: string | undefined) {
    super(`Account request failed with status ${status}.`);
    this.status = status;
    this.kind = classify(status, problemType);
  }
}

function classify(status: number, problemType: string | undefined): AccountErrorKind {
  switch (problemType) {
    case '/problems/stale-version':
      return 'stale';
    case '/problems/account-label-taken':
      return 'conflict';
    case '/problems/account-archived':
      return 'archived';
    default:
      // A refused query and a refused body are both the caller's to correct,
      // and neither is retried by repeating the same request: a business date
      // outside the years the API covers answers 400 and would otherwise be
      // shown as a network failure with a Retry button that can only fail
      // again.
      return status === 409 ? 'conflict' : 400 === status || 422 === status ? 'invalid' : 'network';
  }
}

export function requestFailed(result: { data?: unknown; response?: Response }): boolean {
  return !result.response?.ok || !result.data;
}

export function accountRequestError(result: {
  error?: unknown;
  response?: Response;
}): AccountRequestError {
  const problem = result.error as Problem | undefined;

  return new AccountRequestError(result.response?.status ?? 0, problem?.type);
}

export function accountErrorKind(error: unknown, isError: boolean): AccountErrorKind | null {
  if (error instanceof AccountRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}
