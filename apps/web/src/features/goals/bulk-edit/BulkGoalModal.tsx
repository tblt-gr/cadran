import {
  bulkEditGoals,
  previewBulkGoalEdit,
  type BulkGoalRequest,
  type BulkGoalResult,
  type GoalSummary,
} from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { goalErrorKind, goalRequestError } from '@/features/goals/goalError';
import { useGoalWriteRefresh } from '@/features/goals/goal-panel/useGoalEditor';
import { BulkGoalForm } from './BulkGoalForm';

export function BulkGoalModal({
  goals,
  month,
  close,
  onSaved,
}: {
  goals: GoalSummary[];
  month: string;
  close: () => void;
  onSaved: () => void;
}) {
  const { t } = useTranslation();
  const refresh = useGoalWriteRefresh();
  // A refreshed version may change units. Keep the draft's displayed units fixed so the server
  // can reject a mismatch instead of silently reinterpreting an already entered literal.
  const [selected] = useState(goals);
  const selectedGoals = selected.map((goal) => ({
    ...goal,
    version: goals.find((latest) => latest.id === goal.id)?.version ?? goal.version,
  }));
  const [confirmed, setConfirmed] = useState<{
    body: BulkGoalRequest;
    result: BulkGoalResult;
  } | null>(null);
  const preview = useMutation({
    mutationFn: async (body: BulkGoalRequest) => {
      const result = await withCsrfRetry(() => previewBulkGoalEdit({ ...authApiOptions(), body }));
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return { body, result: result.data };
    },
    onSuccess: setConfirmed,
    onError: () => {
      setConfirmed(null);
      void refresh();
    },
  });
  const save = useMutation({
    mutationFn: async () => {
      if (!confirmed?.result.canApply) return;
      const result = await withCsrfRetry(() =>
        bulkEditGoals({ ...authApiOptions(), body: confirmed.body }),
      );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
    },
    onSuccess: async () => {
      await refresh();
      onSaved();
    },
    onError: () => {
      setConfirmed(null);
      void refresh();
    },
  });
  const busy = preview.isPending || save.isPending;
  const error = goalErrorKind(save.error ?? preview.error, save.isError || preview.isError);
  return (
    <Modal close={busy ? () => {} : close} intent="edit" title={t('goals.bulk.title')}>
      <BulkGoalForm
        busy={busy}
        close={close}
        error={
          error
            ? t(error === 'conflict' ? 'goals.bulk.refreshPreview' : `goals.errors.${error}`)
            : null
        }
        goals={selectedGoals}
        month={month}
        onChange={() => {
          setConfirmed(null);
          preview.reset();
          save.reset();
        }}
        onPreview={(body) => {
          setConfirmed(null);
          save.reset();
          preview.mutate(body);
        }}
        onSave={() => save.mutate()}
        preview={confirmed?.result ?? null}
        previewPending={preview.isPending}
      />
    </Modal>
  );
}
