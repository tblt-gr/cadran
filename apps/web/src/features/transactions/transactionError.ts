import type { Problem } from '@cadran/api-client';

export type TransactionErrorKind = 'conflict' | 'invalid' | 'network' | 'stale' | 'unauthorized';

/**
 * A refused transaction request, classified by its RFC 9457 problem type
 * rather than by status alone: a stale version and a terminal-state conflict
 * are both 409 and ask the user for opposite things.
 */
export class TransactionRequestError extends Error {
  readonly status: number;
  readonly kind: TransactionErrorKind;

  constructor(status: number, problemType: string | undefined) {
    super(`Transaction request failed with status ${status}.`);
    this.status = status;
    this.kind = classify(status, problemType);
  }
}

function classify(status: number, problemType: string | undefined): TransactionErrorKind {
  if (problemType === '/problems/stale-version') {
    return 'stale';
  }
  if (problemType === '/problems/transaction-conflict') {
    return 'conflict';
  }
  if (status === 401 || status === 403) {
    return 'unauthorized';
  }

  return status === 409 ? 'conflict' : status === 400 || status === 422 ? 'invalid' : 'network';
}

export function transactionRequestError(result: {
  error?: unknown;
  response?: Response;
}): TransactionRequestError {
  const problem = result.error as Problem | undefined;

  return new TransactionRequestError(result.response?.status ?? 0, problem?.type);
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
