import { useTranslation } from 'react-i18next';
import styles from './AccountsState.module.css';

export type AccountsStateKind = 'loading' | 'error' | 'unauthorized' | 'empty';

interface AccountsStateProps {
  kind: AccountsStateKind;
  onCreate: () => void;
  onRetry: () => void;
}

/**
 * The states the accounts list can be in besides showing rows: still loading,
 * unreadable, no longer authenticated, or empty. Each one names what happened
 * and offers the single action that resolves it.
 */
export function AccountsState({ kind, onCreate, onRetry }: AccountsStateProps) {
  const { t } = useTranslation();

  if (kind === 'loading') {
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('accounts.loading')}</h2>
      </section>
    );
  }

  if (kind === 'empty') {
    return (
      <section className={`card ${styles.state}`}>
        <h2>{t('accounts.empty.title')}</h2>
        <p>{t('accounts.empty.description')}</p>
        <button className="primary-action" onClick={onCreate} type="button">
          {t('accounts.addFirst')}
        </button>
      </section>
    );
  }

  const unauthorized = kind === 'unauthorized';

  return (
    <section className={`card ${styles.state}`} role="alert">
      <h2>{t(unauthorized ? 'accounts.unauthorized.title' : 'accounts.error.title')}</h2>
      <p>{t(unauthorized ? 'accounts.unauthorized.description' : 'accounts.error.description')}</p>
      {!unauthorized ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          {t('foundation.retry')}
        </button>
      ) : null}
    </section>
  );
}
