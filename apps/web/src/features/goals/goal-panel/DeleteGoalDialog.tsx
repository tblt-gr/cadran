import { deleteGoal, type GoalSummary } from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { goalErrorKind, goalRequestError } from '@/features/goals/goalError';
import styles from './GoalPanel.module.css';
import { useGoalWriteRefresh } from './useGoalEditor';

interface Props {
  goal: GoalSummary;
  close: () => void;
  onDeleted: () => void;
  /** A goal covering a closed month cannot be deleted: the dialog offers to stop it instead. */
  onStopInstead: (goal: GoalSummary) => void;
}

/** Deletes a goal with its versions; refused by the server once it covers a closed month. */
export function DeleteGoalDialog({ goal, close, onDeleted, onStopInstead }: Props) {
  const { t } = useTranslation();
  const refresh = useGoalWriteRefresh();
  const remove = useMutation({
    mutationFn: async () => {
      const result = await withCsrfRetry(() =>
        deleteGoal({
          ...authApiOptions(),
          headers: { ...authApiOptions().headers, 'If-Match': String(goal.version) },
          path: { id: goal.id },
        }),
      );
      if (!result.response?.ok) throw goalRequestError(result);
    },
    onSuccess: async () => {
      await refresh();
      onDeleted();
    },
    // A newer version of the goal exists: reload it so the retry carries the current one.
    onError: (error) => {
      if (goalErrorKind(error, true) === 'conflict') void refresh();
    },
  });
  const error = goalErrorKind(remove.error, remove.isError);
  const coversClosedPeriod = error === 'goal_covers_closed_period';

  return (
    <Modal
      close={close}
      intent="delete"
      title={t('goals.panel.delete.title', { label: goal.label })}
    >
      <div className={styles.dialogForm}>
        <p>{t('goals.panel.delete.description')}</p>
        {error ? <p role="alert">{t(`goals.errors.${error}`)}</p> : null}
        <ModalActions>
          <button className="secondary-action" onClick={close} type="button">
            {t('goals.panel.delete.keep')}
          </button>
          {coversClosedPeriod ? (
            <button
              className="primary-action"
              data-autofocus
              onClick={() => onStopInstead(goal)}
              type="button"
            >
              {t('goals.panel.stop.submit')}
            </button>
          ) : (
            <button
              className="primary-action"
              data-autofocus
              disabled={remove.isPending}
              onClick={() => remove.mutate()}
              type="button"
            >
              {t('goals.panel.delete.submit')}
            </button>
          )}
        </ModalActions>
      </div>
    </Modal>
  );
}
