import type { Problem } from '@cadran/api-client';

export type BudgetErrorKind = 'conflict' | 'invalid' | 'network' | 'unauthorized';

export class BudgetRequestError extends Error {
  readonly kind: BudgetErrorKind;

  constructor(status: number) {
    super(`Budget request failed with status ${status}.`);
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

export function budgetRequestError(result: {
  error?: unknown;
  response?: Response;
}): BudgetRequestError {
  void (result.error as Problem | undefined);
  return new BudgetRequestError(result.response?.status ?? 0);
}

export function budgetErrorKind(error: unknown, failed: boolean): BudgetErrorKind | null {
  return error instanceof BudgetRequestError ? error.kind : failed ? 'network' : null;
}
