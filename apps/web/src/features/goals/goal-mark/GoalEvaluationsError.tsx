import { useTranslation } from 'react-i18next';
import styles from './GoalMark.module.css';

/** The single line a report shows under its figures when the goals could not be evaluated. */
export function GoalEvaluationsError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation();
  return (
    <p className={styles.error} role="alert">
      {t('goals.mark.error')}{' '}
      <button className="secondary-action" onClick={onRetry} type="button">
        {t('reports.retry')}
      </button>
    </p>
  );
}
