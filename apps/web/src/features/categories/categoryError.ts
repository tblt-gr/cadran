import type { CategoryImpactBlocker, CategoryOperationProblem, Problem } from '@cadran/api-client';

export type CategoryErrorKind = 'conflict' | 'invalid' | 'network' | 'refused' | 'unauthorized';

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
    this.kind = classify(status, problem?.type);
    this.blockers = blockers;
  }
}

function classify(status: number, problemType: string | undefined): CategoryErrorKind {
  if (problemType === OPERATION_REFUSED) {
    return 'refused';
  }

  // 401 and 403 are the same answer to the user: sign in again. Telling them to
  // retry, or that the category changed elsewhere, sends them nowhere.
  return status === 401 || status === 403
    ? 'unauthorized'
    : status === 409
      ? 'conflict'
      : status === 400 || status === 422
        ? 'invalid'
        : 'network';
}

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
