import {
  createGoal,
  listGoalVersions,
  previewGoalCorrection,
  readGoal,
  updateGoal,
  type CreateGoalRequest,
  type GoalCorrectionPreview,
  type GoalDetail,
  type GoalPeriodicity,
  type GoalSummary,
  type UpdateGoalRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { useSubmissionKey } from '@/hooks/use-submission-key';
import { goalErrorKind, goalRequestError } from '@/features/goals/goalError';
import { currentMonthIn } from '@/features/goals/goalFormFields';

/** What the goal form opens with when it creates a goal from the panel. */
export interface GoalCreateDefaults {
  periodicity: GoalPeriodicity;
  start: string;
}

/** Report data that shows a goal's evaluation or the figures it is compared with. */
const WRITE_INVALIDATIONS = [
  'goal-evaluations',
  'goals',
  'goal',
  'goal-versions',
  'annual-report',
  'monthly-report',
  'monthly-recap',
  'multi-year-report',
];

export function useGoalWriteRefresh() {
  const queryClient = useQueryClient();
  return () =>
    Promise.all(
      WRITE_INVALIDATIONS.map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
    );
}

/**
 * State of the goal form modal and its history modal: creation from the panel's period, edition
 * of a current goal with the preview-and-confirm step of a retroactive correction, and the
 * version history of a goal.
 */
export function useGoalEditor() {
  const refreshAfterWrite = useGoalWriteRefresh();
  const workspaceMonth = currentMonthIn(useWorkspaceTimeZone());
  const [creating, setCreating] = useState<GoalCreateDefaults | null>(null);
  const [editing, setEditing] = useState<GoalSummary | null>(null);
  const [historyTarget, setHistoryTarget] = useState<GoalSummary | null>(null);
  const [correction, setCorrection] = useState<{
    body: UpdateGoalRequest;
    preview: GoalCorrectionPreview;
  } | null>(null);
  const [correctionConfirmed, setCorrectionConfirmed] = useState(false);
  const [saved, setSaved] = useState(false);
  const submission = useSubmissionKey();

  const detail = useQuery({
    enabled: editing !== null,
    queryKey: ['goal', editing?.id ?? ''],
    queryFn: async ({ signal }) => {
      const result = await readGoal({
        ...authApiOptions(),
        path: { id: editing!.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const history = useQuery({
    enabled: historyTarget !== null,
    queryKey: ['goal-versions', historyTarget?.id],
    queryFn: async ({ signal }) => {
      const result = await listGoalVersions({
        ...authApiOptions(),
        path: { id: historyTarget!.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });

  // A refused version means another session changed the goal: reload it so the preserved draft is
  // resubmitted against the current version instead of repeating the same conflict.
  const reloadOnConflict = (error: unknown) => {
    if (goalErrorKind(error, true) === 'conflict') void refreshAfterWrite();
  };
  const save = useMutation({
    mutationFn: async (body: CreateGoalRequest | UpdateGoalRequest) => {
      const result = editing
        ? await withCsrfRetry(() =>
            updateGoal({
              ...authApiOptions(),
              path: { id: editing.id },
              body: body as UpdateGoalRequest,
            }),
          )
        : await withCsrfRetry(() =>
            createGoal({
              ...authApiOptions(),
              headers: {
                ...authApiOptions().headers,
                'Idempotency-Key': submission.keyFor(body),
              },
              body: body as CreateGoalRequest,
            }),
          );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      submission.release();
      setCreating(null);
      setEditing(null);
      setSaved(true);
      await refreshAfterWrite();
    },
    onError: reloadOnConflict,
  });
  const preview = useMutation({
    mutationFn: async (body: UpdateGoalRequest) => {
      const result = await withCsrfRetry(() =>
        previewGoalCorrection({
          ...authApiOptions(),
          path: { id: editing!.id },
          body,
        }),
      );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return { body, preview: result.data };
    },
    onSuccess: (result) => {
      // No month changes: there is no impact to confirm, so the label-only edit is saved directly.
      if (result.preview.months.length === 0) {
        save.mutate(result.body);
        return;
      }
      setCorrectionConfirmed(false);
      setCorrection(result);
    },
    onError: reloadOnConflict,
  });

  function resetDraft() {
    save.reset();
    preview.reset();
    setCorrection(null);
    setCorrectionConfirmed(false);
  }
  const latestDetail = detail.data as GoalDetail | undefined;
  const editingGoal: GoalSummary | null =
    editing && latestDetail
      ? {
          ...editing,
          label: latestDetail.label,
          currentVersion: latestDetail.currentVersion,
          version: latestDetail.version,
        }
      : editing;

  return {
    creating,
    correction,
    correctionConfirmed,
    detail,
    editing,
    editingGoal,
    history,
    historyTarget,
    latestDetail,
    preview,
    save,
    saved,
    workspaceMonth,
    closeEditor() {
      setCreating(null);
      setEditing(null);
      resetDraft();
    },
    dismissSaved: () => setSaved(false),
    openCreate(defaults: GoalCreateDefaults) {
      resetDraft();
      setCreating(defaults);
    },
    openEdit(goal: GoalSummary) {
      resetDraft();
      setEditing(goal);
    },
    openHistory: setHistoryTarget,
    closeHistory: () => setHistoryTarget(null),
    backToForm() {
      setCorrection(null);
      setCorrectionConfirmed(false);
      save.reset();
      preview.reset();
    },
    setCorrectionConfirmed,
  };
}

export type GoalEditor = ReturnType<typeof useGoalEditor>;
