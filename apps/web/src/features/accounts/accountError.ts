import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type AccountErrorKind =
  'archived' | 'conflict' | 'invalid' | 'network' | 'stale' | 'unauthorized';

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
    this.kind = classifyProblem(status, problemType, PROBLEM_KINDS);
  }
}

const PROBLEM_KINDS: Record<string, AccountErrorKind> = {
  '/problems/stale-account-order': 'stale',
  '/problems/account-label-taken': 'conflict',
  '/problems/account-archived': 'archived',
  // A standing override already covering these dates, or one withdrawn
  // between the read and the write: both ask the caller to reload the
  // account's claims and reapply, not to correct a field.
  '/problems/account-rule-override-conflict': 'conflict',
  '/problems/account-balance-conflict': 'conflict',
};

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
