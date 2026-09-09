import { useTranslation } from 'react-i18next';
import styles from './TransactionsState.module.css';

export type TransactionsStateKind = 'loading' | 'error' | 'unauthorized' | 'empty';

interface TransactionsStateProps {
  kind: TransactionsStateKind;
  onCreate: () => void;
  onRetry: () => void;
}

export function TransactionsState({ kind, onCreate, onRetry }: TransactionsStateProps) {
  const { t } = useTranslation();

  if (kind === 'loading') {
    return (
      <section aria-busy="true" className={`card ${styles.state}`} role="status">
        <h2>{t('transactions.loading')}</h2>
      </section>
    );
  }

  if (kind === 'empty') {
    return (
      <section className={`card ${styles.state}`}>
        <h2>{t('transactions.empty.title')}</h2>
        <p>{t('transactions.empty.description')}</p>
        <button className="primary-action" onClick={onCreate} type="button">
          {t('transactions.addFirst')}
        </button>
      </section>
    );
  }

  const unauthorized = kind === 'unauthorized';

  return (
    <section className={`card ${styles.state}`} role="alert">
      <h2>{t(unauthorized ? 'transactions.unauthorized.title' : 'transactions.error.title')}</h2>
      <p>
        {t(
          unauthorized ? 'transactions.unauthorized.description' : 'transactions.error.description',
        )}
      </p>
      {!unauthorized ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          {t('foundation.retry')}
        </button>
      ) : null}
    </section>
  );
}
