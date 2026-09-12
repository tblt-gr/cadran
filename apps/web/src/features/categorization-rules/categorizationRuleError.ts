import type { Problem } from '@cadran/api-client';

export type CategorizationRuleErrorKind = 'invalid' | 'network' | 'stale' | 'unauthorized';

const PREVIEW_STALE = '/problems/rules.preview_stale';

export class CategorizationRuleRequestError extends Error {
  readonly kind: CategorizationRuleErrorKind;

  constructor(status: number, problem?: Problem) {
    super(`Categorization rule request failed with status ${status}.`);
    this.kind =
      problem?.type === PREVIEW_STALE || status === 409
        ? 'stale'
        : status === 401 || status === 403
          ? 'unauthorized'
          : status === 400 || status === 404 || status === 422
            ? 'invalid'
            : 'network';
  }
}

export function categorizationRuleRequestError(result: {
  error?: unknown;
  response?: Response;
}): CategorizationRuleRequestError {
  return new CategorizationRuleRequestError(result.response?.status ?? 0, result.error as Problem);
}

export function categorizationRuleErrorKind(
  error: unknown,
  isError: boolean,
): CategorizationRuleErrorKind | null {
  return error instanceof CategorizationRuleRequestError ? error.kind : isError ? 'network' : null;
}
