import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type TransactionErrorKind =
  | 'conflict'
  | 'invalid'
  | 'network'
  | 'periodClosed'
  | 'portfolioOperationsRequired'
  | 'stale'
  | 'staleCursor'
  | 'unauthorized';

/**
 * A refused transaction request, classified by its RFC 9457 problem type
 * rather than by status alone: a stale version and a terminal-state conflict
 * are both 409 and ask the user for opposite things.
 */
export class TransactionRequestError extends Error {
  readonly status: number;
  readonly kind: TransactionErrorKind;
  /** The server's own translated explanation, when the response carried one. */
  readonly detail: string | undefined;

  constructor(status: number, problem: Problem | undefined) {
    super(`Transaction request failed with status ${status}.`);
    this.status = status;
    this.kind = classifyProblem(status, problem?.type, PROBLEM_KINDS);
    this.detail = problem?.detail;
  }
}

const PROBLEM_KINDS: Record<string, TransactionErrorKind> = {
  '/problems/period-closed': 'periodClosed',
  '/problems/portfolio-account-requires-operations': 'portfolioOperationsRequired',
  '/problems/transaction-conflict': 'conflict',
  '/problems/transactions.cursor_stale': 'staleCursor',
};

export function transactionRequestError(result: {
  error?: unknown;
  response?: Response;
}): TransactionRequestError {
  const problem = result.error as Problem | undefined;

  return new TransactionRequestError(result.response?.status ?? 0, problem);
}

export function transactionErrorKind(
  error: unknown,
  isError: boolean,
): TransactionErrorKind | null {
  if (error instanceof TransactionRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}

/** The server's own explanation, when the error carried one - e.g. a refund's exact remaining balance. */
export function transactionErrorDetail(error: unknown): string | null {
  return error instanceof TransactionRequestError ? (error.detail ?? null) : null;
}
