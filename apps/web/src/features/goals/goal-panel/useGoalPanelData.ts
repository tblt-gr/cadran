import {
  listGoalEvaluations,
  listGoals,
  type GoalEvaluations,
  type GoalSummary,
  type ListGoalEvaluationsData,
} from '@cadran/api-client';
import { useQueries, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { goalRequestError } from '@/features/goals/goalError';

/**
 * The period a panel opens for: a month of the monthly budget, a year of the annual report, or the
 * years selected on the multi-year view.
 */
export type GoalPanelScope =
  | { kind: 'month'; month: string }
  | { kind: 'year'; year: number }
  | { kind: 'years'; years: number[] };

// A page holds at most 100 goals and the list is oldest first: a recent goal can sit on a later
// page, so the list is read page after page. The server evaluates at most 500 goals.
const GOALS_PAGE_SIZE = 100;
const MAX_GOAL_PAGES = 10;

type GoalEvaluationGranularity = NonNullable<ListGoalEvaluationsData['query']['granularity']>;

export interface EvaluationRange {
  from: string;
  to: string;
  granularity: GoalEvaluationGranularity;
}

/**
 * One range per request: the server caps a request at 120 months, so each year is its own. A month
 * asks for its monthly goals only, a year for both periodicities: the same keys as the reports.
 */
function scopeRanges(scope: GoalPanelScope): EvaluationRange[] {
  if (scope.kind === 'month') {
    return [{ from: scope.month, to: scope.month, granularity: 'MONTH' }];
  }
  const years = scope.kind === 'year' ? [scope.year] : scope.years;
  return years.map((year) => ({ from: `${year}-01`, to: `${year}-12`, granularity: 'BOTH' }));
}

// Recomputing an evaluation costs about an annual report: reuse it for a minute. A write
// invalidates it whatever its age.
const EVALUATIONS_STALE_TIME_MS = 60_000;

function evaluationQueryOptions({ from, to, granularity }: EvaluationRange) {
  return {
    queryKey: ['goal-evaluations', from, to, granularity],
    queryFn: async ({ signal }: { signal: AbortSignal }): Promise<GoalEvaluations> => {
      const result = await listGoalEvaluations({
        ...authApiOptions(),
        query: { from, to, granularity },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    staleTime: EVALUATIONS_STALE_TIME_MS,
    retry: false,
  };
}

/**
 * Evaluations of one range, for a report that marks its figures: the annual report asks for
 * `BOTH` over its year, the multi-year report for `YEAR` over its years (up to 30), the monthly
 * budget for `MONTH`. The same keys as the panel, so the cache is shared.
 */
export function useGoalEvaluationRange(range: EvaluationRange, enabled = true) {
  const query = useQuery({ ...evaluationQueryOptions(range), enabled });
  return {
    evaluations: query.data,
    failed: query.isError,
    retry: () => void query.refetch(),
  };
}

/**
 * Evaluations of the period. The ranges are requested one after the other, so at most one request
 * is in flight: a range starts once the previous one has settled, a cached one settles at once.
 * `enabled` holds everything back, for a caller that waits for its own main data.
 */
export function useGoalEvaluations(scope: GoalPanelScope, enabled = true) {
  const ranges = scopeRanges(scope);
  const [unlocked, setUnlocked] = useState(1);
  const evaluations = useQueries({
    queries: ranges.map((range, index) => ({
      ...evaluationQueryOptions(range),
      enabled: enabled && index < unlocked,
    })),
  });
  let settledPrefix = 0;
  while (
    settledPrefix < evaluations.length &&
    (evaluations[settledPrefix]!.isSuccess || evaluations[settledPrefix]!.isError)
  ) {
    settledPrefix += 1;
  }
  // The next range may start: adjusting state while rendering is React's way to derive it.
  if (enabled && settledPrefix + 1 > unlocked && unlocked < ranges.length) {
    setUnlocked(Math.min(settledPrefix + 1, ranges.length));
  }
  // Every range answers before anything shows: the answers merge into one payload.
  const merged: GoalEvaluations | undefined = evaluations.every((query) => query.data !== undefined)
    ? {
        months: evaluations.flatMap((query) => query.data!.months),
        years: evaluations.flatMap((query) => query.data!.years),
      }
    : undefined;

  return {
    evaluations: merged,
    pending: evaluations.some((query) => query.isPending),
    failed: evaluations.some((query) => query.isError),
    retry() {
      for (const query of evaluations) if (query.isError) void query.refetch();
    },
  };
}

/** The goals with their scope, recurrence and version: needed by the panel's rows only. */
function useGoalSummaries(enabled = true) {
  const goals = useQuery({
    enabled,
    queryKey: ['goals', 'panel'],
    queryFn: async ({ signal }): Promise<GoalSummary[]> => {
      const goals: GoalSummary[] = [];
      for (let page = 1; page <= MAX_GOAL_PAGES; page += 1) {
        const result = await listGoals({
          ...authApiOptions(),
          query: { current: false, page, pageSize: GOALS_PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) throw goalRequestError(result);
        goals.push(...result.data.items);
        if (!(goals.length < (result.data.total ?? 0)) || result.data.items.length === 0) break;
      }
      return goals;
    },
    retry: false,
  });
  return {
    goals: goals.data,
    pending: goals.isPending,
    failed: goals.isError,
    retry: () => void goals.refetch(),
  };
}

/** Everything the open panel shows: the evaluations and the goals they refer to. */
export function useGoalPanelData(scope: GoalPanelScope) {
  const evaluations = useGoalEvaluations(scope);
  const summaries = useGoalSummaries();
  return {
    evaluations: evaluations.evaluations,
    goals: summaries.goals,
    pending: evaluations.pending || summaries.pending,
    failed: evaluations.failed || summaries.failed,
    retry() {
      evaluations.retry();
      if (summaries.failed) summaries.retry();
    },
  };
}
