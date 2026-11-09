import {
  bulkDeleteGoals,
  bulkStopGoals,
  type GoalProblem,
  type GoalSummary,
} from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { GoalRequestError } from '@/features/goals/goalError';
import { useGoalWriteRefresh } from '@/features/goals/goal-panel/useGoalEditor';
import {
  BulkGoalLifecycleForm,
  type GoalBulkAction,
  type GoalStopPeriods,
} from './BulkGoalLifecycleForm';

class BulkActionError extends GoalRequestError {
  readonly status: number;
  readonly problem: GoalProblem | undefined;

  constructor(status: number, problem: GoalProblem | undefined) {
    super(status, problem);
    this.status = status;
    this.problem = problem;
  }
}

/** One confirmation and one atomic request for exactly the identifiers selected at opening. */
export function BulkGoalLifecycleDialog({
  action,
  goals,
  month,
  year,
  close,
  onSaved,
}: {
  action: GoalBulkAction;
  goals: GoalSummary[];
  month: string;
  year: number;
  close: () => void;
  onSaved: () => void;
}) {
  const { t } = useTranslation();
  const refresh = useGoalWriteRefresh();
  const [selected] = useState(goals);
  const selection = selected.map((goal) => ({
    ...goal,
    version: goals.find((latest) => latest.id === goal.id)?.version ?? goal.version,
  }));
  const save = useMutation({
    mutationFn: async (periods: GoalStopPeriods) => {
      const result = await withCsrfRetry(() =>
        action === 'stop'
          ? bulkStopGoals({
              ...authApiOptions(),
              body: {
                items: selection.map((goal) => ({
                  goalId: goal.id,
                  expectedVersion: goal.version,
                  lastPeriod: goal.periodicity === 'MONTHLY' ? periods.monthly : periods.yearly,
                })),
              },
            })
          : bulkDeleteGoals({
              ...authApiOptions(),
              body: {
                items: selection.map((goal) => ({
                  goalId: goal.id,
                  expectedVersion: goal.version,
                })),
              },
            }),
      );
      if (!result.response?.ok)
        throw new BulkActionError(
          result.response?.status ?? 0,
          result.error as GoalProblem | undefined,
        );
    },
    onSuccess: async () => {
      await refresh();
      onSaved();
    },
    onError: async () => {
      await refresh();
    },
  });
  const failure = save.error instanceof BulkActionError ? save.error : null;
  const error = failure
    ? t(
        failure.status === 404
          ? 'goals.bulk.unavailable'
          : failure.kind === 'conflict'
            ? 'goals.bulk.conflict'
            : failure.kind === 'network'
              ? 'goals.errors.network'
              : 'goals.bulk.batchFailure',
        { message: t(`goals.errors.${failure.kind}`) },
      )
    : save.isError
      ? t('goals.errors.network')
      : null;
  const blockedGoal = selection.find((goal) => goal.id === failure?.problem?.goalId);
  const rowErrors =
    blockedGoal && failure
      ? [
          t('goals.bulk.rowError', {
            label: blockedGoal.label,
            field: t(
              failure.problem?.field === 'lastPeriod'
                ? 'goals.bulk.fields.lastPeriod'
                : failure.problem?.field === 'expectedVersion'
                  ? 'goals.bulk.fields.expectedVersion'
                  : 'goals.bulk.fields.goal',
            ),
            message: t(`goals.errors.${failure.kind}`),
          }),
        ]
      : [];
  return (
    <Modal
      close={save.isPending ? () => {} : close}
      intent={action === 'delete' ? 'delete' : 'confirm'}
      title={t(`goals.bulk.${action}Title`, { count: selection.length })}
    >
      <BulkGoalLifecycleForm
        action={action}
        goals={selection}
        month={month}
        year={year}
        busy={save.isPending}
        error={error}
        rowErrors={rowErrors}
        close={close}
        onConfirm={(periods) => save.mutate(periods)}
      />
    </Modal>
  );
}
