import type { Problem } from '@cadran/api-client';

export type NetWorthErrorKind = 'error' | 'unauthorized';

/**
 * A refused net-worth read. A caller attached to no workspace is told so
 * plainly instead of seeing the generic outage copy.
 */
export class NetWorthRequestError extends Error {
  readonly kind: NetWorthErrorKind;

  constructor(status: number) {
    super(`Net worth request failed with status ${status}.`);
    this.kind = status === 403 ? 'unauthorized' : 'error';
  }
}

export function netWorthRequestError(result: { error?: unknown; response?: Response }): Error {
  const problem = result.error as Problem | undefined;

  return new NetWorthRequestError(problem?.status ?? result.response?.status ?? 0);
}

export function netWorthErrorKind(error: unknown, isError: boolean): NetWorthErrorKind | null {
  if (error instanceof NetWorthRequestError) {
    return error.kind;
  }

  return isError ? 'error' : null;
}
