import type { Problem } from '@cadran/api-client';

export type GroupErrorKind = 'conflict' | 'invalid' | 'network' | 'stale';

/**
 * A refused group request, classified by its RFC 9457 problem type rather
 * than by its status alone: a taken sibling label and a stale version are
 * both 409 and ask the user for opposite things.
 */
export class GroupRequestError extends Error {
  readonly status: number;
  readonly kind: GroupErrorKind;

  constructor(status: number, problemType: string | undefined) {
    super(`Account group request failed with status ${status}.`);
    this.status = status;
    this.kind = classify(status, problemType);
  }
}

function classify(status: number, problemType: string | undefined): GroupErrorKind {
  switch (problemType) {
    case '/problems/stale-version':
      return 'stale';
    case '/problems/account-group-label-taken':
      return 'conflict';
    default:
      return status === 409 ? 'conflict' : 400 === status || 422 === status ? 'invalid' : 'network';
  }
}

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
  const problem = result.error as Problem | undefined;

  return new GroupRequestError(result.response?.status ?? 0, problem?.type);
}
