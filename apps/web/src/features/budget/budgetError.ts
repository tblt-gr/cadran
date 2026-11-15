import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type BudgetErrorKind = 'conflict' | 'invalid' | 'network' | 'unauthorized';

export class BudgetRequestError extends Error {
  readonly kind: BudgetErrorKind;

  constructor(status: number) {
    super(`Budget request failed with status ${status}.`);
    const kind = classifyProblem<never>(status, undefined, {});
    // No problem type is read here, so a stale version is a plain conflict.
    this.kind = kind === 'stale' ? 'conflict' : kind;
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
