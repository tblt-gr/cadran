import type { CategoryImpactBlocker, CategoryOperationProblem, Problem } from '@cadran/api-client';

import { classifyProblem } from '@/lib/apiProblem';

export type CategoryErrorKind =
  'conflict' | 'invalid' | 'network' | 'periodClosed' | 'refused' | 'unauthorized';

const OPERATION_REFUSED = '/problems/category-operation-refused';

/**
 * A refused category request, classified by its RFC 9457 problem type rather
 * than by its status alone: an impact refusal and an invalid body are both 422
 * and ask the user for opposite things, and a refusal names every reason it has
 * so the interface can explain them all at once.
 */
export class CategoryRequestError extends Error {
  readonly status: number;
  readonly kind: CategoryErrorKind;
  readonly blockers: CategoryImpactBlocker[];

  constructor(status: number, problem: Problem | undefined, blockers: CategoryImpactBlocker[]) {
    super(`Category request failed with status ${status}.`);
    this.status = status;
    const kind = classifyProblem(status, problem?.type, PROBLEM_KINDS);
    // A stale version carries no dedicated message here: it asks for the same
    // reload as any other concurrent change.
    this.kind = kind === 'stale' ? 'conflict' : kind;
    this.blockers = blockers;
  }
}

const PROBLEM_KINDS: Record<string, CategoryErrorKind> = {
  [OPERATION_REFUSED]: 'refused',
  '/problems/period-closed': 'periodClosed',
};

export function categoryRequestError(result: {
  error?: unknown;
  response?: Response;
}): CategoryRequestError {
  const problem = result.error as Problem | CategoryOperationProblem | undefined;

  return new CategoryRequestError(result.response?.status ?? 0, problem, readBlockers(problem));
}

export function categoryErrorKind(error: unknown, isError: boolean): CategoryErrorKind | null {
  if (error instanceof CategoryRequestError) {
    return error.kind;
  }

  return isError ? 'network' : null;
}

export function categoryErrorBlockers(error: unknown): CategoryImpactBlocker[] {
  return error instanceof CategoryRequestError ? error.blockers : [];
}

function readBlockers(
  problem: Problem | CategoryOperationProblem | undefined,
): CategoryImpactBlocker[] {
  return problem !== undefined && 'blockers' in problem ? problem.blockers : [];
}
