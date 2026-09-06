import type { Problem } from '@cadran/api-client';

/**
 * A refused profile request, classified by its RFC 9457 problem type rather
 * than by its status: the three 422 refusals of a password change point at
 * different fields, and only the type tells them apart.
 */
export type ProfileErrorKind =
  | 'currentPassword'
  | 'displayName'
  | 'network'
  | 'reused'
  | 'throttled'
  | 'unauthorized'
  | 'weakPassword';

const PROBLEM_KINDS: Record<string, ProfileErrorKind> = {
  '/problems/invalid-current-password': 'currentPassword',
  '/problems/invalid-display-name': 'displayName',
  '/problems/reused-password': 'reused',
  '/problems/weak-password': 'weakPassword',
};

export class ProfileRequestError extends Error {
  readonly kind: ProfileErrorKind;

  constructor(status: number, problem: Problem | undefined) {
    super(`Profile request failed with status ${status}.`);
    this.kind = classify(status, problem?.type);
  }
}

function classify(status: number, problemType: string | undefined): ProfileErrorKind {
  const byType = problemType === undefined ? undefined : PROBLEM_KINDS[problemType];
  if (byType !== undefined) {
    return byType;
  }

  if (status === 429) {
    return 'throttled';
  }

  // 401 and 403 ask the user for the same thing: sign in again. A CSRF failure
  // lands here too, after withCsrfRetry has already replanted the token once.
  return status === 401 || status === 403 ? 'unauthorized' : 'network';
}

export function profileRequestError(result: {
  error?: unknown;
  response?: Response;
}): ProfileRequestError {
  return new ProfileRequestError(result.response?.status ?? 0, result.error as Problem | undefined);
}

export function profileErrorKind(error: unknown, isError: boolean): ProfileErrorKind | null {
  if (error instanceof ProfileRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}
