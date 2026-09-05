import { useTranslation } from 'react-i18next';
import styles from './DashboardState.module.css';

interface DashboardStateProps {
  kind: 'error' | 'loading' | 'unauthorized';
  onRetry: () => void;
}

/**
 * What the net-worth cards show while there is no figure to show: a wait, an
 * outage or a caller attached to no workspace. Each is named; none of them
 * borrows the shape of a zero.
 */
export function DashboardState({ kind, onRetry }: DashboardStateProps) {
  const { t } = useTranslation();

  if (kind === 'loading') {
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('dashboard.netWorth.loading')}</h2>
      </section>
    );
  }

  return (
    <section className={`card ${styles.state}`} role="alert">
      <h2>
        {t(
          kind === 'unauthorized' ? 'dashboard.netWorth.unauthorized.title' : 'states.error.title',
        )}
      </h2>
      <p>
        {t(
          kind === 'unauthorized'
            ? 'dashboard.netWorth.unauthorized.description'
            : 'states.error.description',
        )}
      </p>
      {kind === 'error' ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          {t('dashboard.netWorth.retry')}
        </button>
      ) : null}
    </section>
  );
}
