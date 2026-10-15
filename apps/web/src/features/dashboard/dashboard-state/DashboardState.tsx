import { useTranslation } from 'react-i18next';
import styles from './DashboardState.module.css';

interface DashboardStateProps {
  kind: 'error' | 'unauthorized';
  onRetry: () => void;
}

/**
 * What the net-worth cards show when the figure cannot be read: an outage or
 * a caller attached to no workspace (loading is the page skeleton's job). Each
 * is named; none of them borrows the shape of a zero.
 */
export function DashboardState({ kind, onRetry }: DashboardStateProps) {
  const { t } = useTranslation();

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
