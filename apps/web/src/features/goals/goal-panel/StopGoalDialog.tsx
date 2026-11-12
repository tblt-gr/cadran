import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { stopGoal, type GoalSummary } from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useId, useState, type FormEvent } from 'react';
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
  /** The period the form opens with: the one before the period the panel shows. */
  defaultLastPeriod: string;
  close: () => void;
  onStopped: () => void;
}

/** Ends a goal after the chosen last period; the server keeps the closed months it covered. */
export function StopGoalDialog({ goal, defaultLastPeriod, close, onStopped }: Props) {
  const { t } = useTranslation();
  const fieldId = useId();
  const monthly = goal.periodicity === 'MONTHLY';
  const [lastPeriod, setLastPeriod] = useState(defaultLastPeriod);
  const refresh = useGoalWriteRefresh();
  const valid = monthly ? /^\d{4}-(0[1-9]|1[0-2])$/.test(lastPeriod) : /^\d{4}$/.test(lastPeriod);
  const stop = useMutation({
    mutationFn: async () => {
      const result = await withCsrfRetry(() =>
        stopGoal({
          ...authApiOptions(),
          path: { id: goal.id },
          body: { expectedVersion: goal.version, lastPeriod },
        }),
      );
      if (!result.response?.ok) throw goalRequestError(result);
    },
    onSuccess: async () => {
      await refresh();
      onStopped();
    },
    // A newer version of the goal exists: reload it so the next attempt carries the current one.
    onError: (error) => {
      if (goalErrorKind(error, true) === 'conflict') void refresh();
    },
  });
  const error = goalErrorKind(stop.error, stop.isError);

  function submit(event: FormEvent) {
    event.preventDefault();
    if (valid) stop.mutate();
  }

  return (
    <Modal
      close={close}
      intent="confirm"
      title={t('goals.panel.stop.title', { label: goal.label })}
    >
      <ModalForm className={styles.dialogForm} noValidate onSubmit={submit}>
        <p>{t('goals.panel.stop.description')}</p>
        {error ? <p role="alert">{t(`goals.errors.${error}`)}</p> : null}
        <label htmlFor={fieldId}>
          <span>{t(monthly ? 'goals.panel.stop.lastMonth' : 'goals.panel.stop.lastYear')}</span>
          <input
            aria-invalid={valid ? undefined : true}
            data-autofocus
            id={fieldId}
            inputMode={monthly ? undefined : 'numeric'}
            maxLength={monthly ? undefined : 4}
            onChange={(event) => setLastPeriod(event.target.value)}
            type={monthly ? 'month' : 'text'}
            value={lastPeriod}
          />
        </label>
        <ModalActions>
          <button className="primary-action" disabled={!valid || stop.isPending} type="submit">
            {t('goals.panel.stop.submit')}
          </button>
        </ModalActions>
      </ModalForm>
    </Modal>
  );
}
