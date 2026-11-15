import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type ProductModelErrorKind =
  'archived' | 'conflict' | 'invalid' | 'network' | 'stale' | 'unauthorized';

/**
 * A refused product-model request, classified by its RFC 9457 problem type
 * rather than by status alone: a taken name and a stale version are both 409
 * and ask the user for opposite things — pick another name, or reload and
 * reapply.
 */
export class ProductModelRequestError extends Error {
  readonly status: number;
  readonly kind: ProductModelErrorKind;

  constructor(status: number, problemType: string | undefined) {
    super(`Product model request failed with status ${status}.`);
    this.status = status;
    this.kind = classifyProblem(status, problemType, PROBLEM_KINDS);
  }
}

const PROBLEM_KINDS: Record<string, ProductModelErrorKind> = {
  '/problems/product-model-name-taken': 'conflict',
  '/problems/product-model-archived': 'archived',
};

export function requestFailed(result: { data?: unknown; response?: Response }): boolean {
  return !result.response?.ok || !result.data;
}

export function productModelRequestError(result: {
  error?: unknown;
  response?: Response;
}): ProductModelRequestError {
  const problem = result.error as Problem | undefined;

  return new ProductModelRequestError(result.response?.status ?? 0, problem?.type);
}

export function productModelErrorKind(
  error: unknown,
  isError: boolean,
): ProductModelErrorKind | null {
  if (error instanceof ProductModelRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}
