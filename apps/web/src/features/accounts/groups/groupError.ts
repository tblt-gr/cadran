import type { AccountGroupInputProblem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type GroupErrorKind =
  'color' | 'conflict' | 'invalid' | 'network' | 'stale' | 'staleOrder' | 'unauthorized';

/**
 * A refused group request, classified by its RFC 9457 problem type rather
 * than by its status alone: a taken sibling label and a stale version are
 * both 409 and ask the user for opposite things.
 */
export class GroupRequestError extends Error {
  readonly status: number;
  readonly kind: GroupErrorKind;

  constructor(status: number, problemType: string | undefined, invalidColor = false) {
    super(`Account group request failed with status ${status}.`);
    this.status = status;
    this.kind = invalidColor ? 'color' : classifyProblem(status, problemType, PROBLEM_KINDS);
  }
}

const PROBLEM_KINDS: Record<string, GroupErrorKind> = {
  '/problems/stale-account-group-order': 'staleOrder',
  '/problems/account-group-label-taken': 'conflict',
};

export function groupErrorKind(error: unknown, isError: boolean): GroupErrorKind | null {
  if (error instanceof GroupRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}

export function groupRequestError(result: {
  error?: unknown;
  response?: Response;
}): GroupRequestError {
  const problem = result.error as AccountGroupInputProblem | undefined;

  return new GroupRequestError(
    result.response?.status ?? 0,
    problem?.type,
    problem?.errors?.some((error) => error.field === 'color' || error.field === 'colorMode') ??
      false,
  );
}
