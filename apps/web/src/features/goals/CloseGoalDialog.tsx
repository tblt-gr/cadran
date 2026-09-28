import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { GoalErrorKind } from './goalError';
import styles from './GoalsPage.module.css';

export function CloseGoalDialog({
  goal,
  pending,
  error,
  onConfirm,
}: {
  goal: GoalSummary;
  pending: boolean;
  error: GoalErrorKind | null;
  onConfirm: () => void;
}) {
  const { t } = useTranslation();
  return (
    <form
      className={styles.closeDialog}
      onSubmit={(event) => {
        event.preventDefault();
        onConfirm();
      }}
    >
      <p>{t('goals.closeDescription', { label: goal.label })}</p>
      {error ? (
        <p className={styles.alert} role="alert">
          {t(`goals.errors.${error}`)}
        </p>
      ) : null}
      <div className={styles.formActions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'goals.closing' : 'goals.close')}
        </button>
      </div>
    </form>
  );
}
