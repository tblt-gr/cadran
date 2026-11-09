import type { GoalSummary } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import styles from './GoalSelection.module.css';

export type GoalBulkAction = 'stop' | 'delete';
export interface GoalStopPeriods {
  monthly: string;
  yearly: string;
}

function previousMonth(month: string) {
  const year = Number(month.slice(0, 4));
  const number = Number(month.slice(5));
  return number === 1 ? `${year - 1}-12` : `${year}-${String(number - 1).padStart(2, '0')}`;
}

export function BulkGoalLifecycleForm({
  action,
  goals,
  month,
  year,
  busy,
  error,
  rowErrors,
  close,
  onConfirm,
}: {
  action: GoalBulkAction;
  goals: GoalSummary[];
  month: string;
  year: number;
  busy: boolean;
  error: string | null;
  rowErrors: string[];
  close: () => void;
  onConfirm: (periods: GoalStopPeriods) => void;
}) {
  const { t } = useTranslation();
  const [monthly, setMonthly] = useState(() => previousMonth(month));
  const [yearly, setYearly] = useState(String(year - 1));
  const hasMonthly = goals.some((goal) => goal.periodicity === 'MONTHLY');
  const hasYearly = goals.some((goal) => goal.periodicity === 'YEARLY');
  const valid =
    goals.length > 0 &&
    (action === 'delete' ||
      ((!hasMonthly || /^\d{4}-(0[1-9]|1[0-2])$/.test(monthly)) &&
        (!hasYearly || /^\d{4}$/.test(yearly))));
  return (
    <form
      className={styles.form}
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        if (valid && !busy) onConfirm({ monthly, yearly });
      }}
    >
      <p>{t(`goals.bulk.${action}Description`, { count: goals.length })}</p>
      <ul>
        {goals.map((goal) => (
          <li key={goal.id}>
            {goal.label}{' '}
            <span className={styles.periodicity}>
              ({t(`goals.periodicities.${goal.periodicity}`)})
            </span>
          </li>
        ))}
      </ul>
      {error ? (
        <div className={styles.error} role="alert">
          <p>{error}</p>
          {rowErrors.length > 0 ? (
            <ul>
              {rowErrors.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
      {action === 'stop' ? (
        <fieldset disabled={busy}>
          <FormGrid>
            {hasMonthly ? (
              <div className={styles.field}>
                <label>
                  <span>{t('goals.panel.stop.lastMonth')}</span>
                  <input
                    aria-invalid={/^\d{4}-(0[1-9]|1[0-2])$/.test(monthly) ? undefined : true}
                    data-autofocus
                    type="month"
                    value={monthly}
                    onChange={(event) => setMonthly(event.target.value)}
                  />
                </label>
                <p>{t('goals.bulk.stopMonthlyImpact')}</p>
              </div>
            ) : null}
            {hasYearly ? (
              <div className={styles.field}>
                <label>
                  <span>{t('goals.panel.stop.lastYear')}</span>
                  <input
                    aria-invalid={/^\d{4}$/.test(yearly) ? undefined : true}
                    data-autofocus={hasMonthly ? undefined : true}
                    inputMode="numeric"
                    maxLength={4}
                    type="text"
                    value={yearly}
                    onChange={(event) => setYearly(event.target.value)}
                  />
                </label>
                <p>{t('goals.bulk.stopYearlyImpact')}</p>
              </div>
            ) : null}
          </FormGrid>
        </fieldset>
      ) : null}
      <ModalActions>
        <button className="secondary-action" disabled={busy} onClick={close} type="button">
          {t('goals.bulk.cancel')}
        </button>
        <button
          className="primary-action"
          data-autofocus={action === 'delete' ? true : undefined}
          disabled={!valid || busy}
          type="submit"
        >
          {t(`goals.bulk.${action}`)}
        </button>
      </ModalActions>
    </form>
  );
}
