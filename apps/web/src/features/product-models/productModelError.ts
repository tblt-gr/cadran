import type { Problem } from '@cadran/api-client';

export type ProductModelErrorKind = 'archived' | 'conflict' | 'invalid' | 'network' | 'stale';

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
    this.kind = classify(status, problemType);
  }
}

function classify(status: number, problemType: string | undefined): ProductModelErrorKind {
  switch (problemType) {
    case '/problems/stale-version':
      return 'stale';
    case '/problems/product-model-name-taken':
      return 'conflict';
    case '/problems/product-model-archived':
      return 'archived';
    default:
      // A refused query and a refused body are both the caller's to correct,
      // and neither is retried by repeating the same request.
      return status === 409 ? 'conflict' : 400 === status || 422 === status ? 'invalid' : 'network';
  }
}

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
