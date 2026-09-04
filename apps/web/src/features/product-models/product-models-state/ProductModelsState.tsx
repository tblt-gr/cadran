import { useTranslation } from 'react-i18next';
import styles from './ProductModelsState.module.css';

export type ProductModelsStateKind = 'loading' | 'error' | 'unauthorized' | 'empty';

interface ProductModelsStateProps {
  kind: ProductModelsStateKind;
  onCreate: () => void;
  onRetry: () => void;
}

/**
 * The states the list can be in besides showing rows: still loading,
 * unreadable, no longer authenticated, or empty. Each names what happened and
 * offers the one action that resolves it.
 */
export function ProductModelsState({ kind, onCreate, onRetry }: ProductModelsStateProps) {
  const { t } = useTranslation();

  if (kind === 'loading') {
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('productModels.loading')}</h2>
      </section>
    );
  }

  if (kind === 'empty') {
    return (
      <section className={`card ${styles.state}`}>
        <h2>{t('productModels.empty.title')}</h2>
        <p>{t('productModels.empty.description')}</p>
        <button className="primary-action" onClick={onCreate} type="button">
          {t('productModels.addFirst')}
        </button>
      </section>
    );
  }

  const unauthorized = kind === 'unauthorized';

  return (
    <section className={`card ${styles.state}`} role="alert">
      <h2>{t(unauthorized ? 'productModels.unauthorized.title' : 'productModels.error.title')}</h2>
      <p>
        {t(
          unauthorized
            ? 'productModels.unauthorized.description'
            : 'productModels.error.description',
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
