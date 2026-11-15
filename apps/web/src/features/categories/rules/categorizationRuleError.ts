import type { Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type CategorizationRuleErrorKind =
  'executionLimit' | 'invalid' | 'network' | 'stale' | 'unauthorized';

const PREVIEW_STALE = '/problems/rules.preview_stale';
const EXECUTION_LIMIT = '/problems/rules.execution_limit';

const PROBLEM_KINDS: Record<string, CategorizationRuleErrorKind> = {
  [PREVIEW_STALE]: 'stale',
  [EXECUTION_LIMIT]: 'executionLimit',
};

export class CategorizationRuleRequestError extends Error {
  readonly kind: CategorizationRuleErrorKind;

  constructor(status: number, problem?: Problem) {
    super(`Categorization rule request failed with status ${status}.`);
    const kind = classifyProblem(status, problem?.type, PROBLEM_KINDS);
    // A rule or preview refused as missing is the caller's to correct, and a
    // concurrent write asks for a fresh preview.
    this.kind =
      kind === 'conflict' ? 'stale' : kind === 'network' && status === 404 ? 'invalid' : kind;
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
