export type GoalErrorKind = 'conflict' | 'invalid' | 'network' | 'unauthorized';

export class GoalRequestError extends Error {
  readonly kind: GoalErrorKind;
  constructor(status: number) {
    super(`Goal request failed with status ${status}.`);
    this.kind =
      status === 401 || status === 403
        ? 'unauthorized'
        : status === 409
          ? 'conflict'
          : status === 400 || status === 422
            ? 'invalid'
            : 'network';
  }
}

export function goalRequestError(result: { response?: Response }): GoalRequestError {
  return new GoalRequestError(result.response?.status ?? 0);
}

export function goalErrorKind(error: unknown, failed: boolean): GoalErrorKind | null {
  return error instanceof GoalRequestError ? error.kind : failed ? 'network' : null;
}
