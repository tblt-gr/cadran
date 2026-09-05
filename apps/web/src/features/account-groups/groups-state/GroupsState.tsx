import { useTranslation } from 'react-i18next';
import styles from './GroupsState.module.css';

interface GroupsStateProps {
  includeArchived: boolean;
  kind: 'loading' | 'error' | 'unauthorized' | 'empty';
  onCreate: () => void;
  onRetry: () => void;
}

export function GroupsState({ includeArchived, kind, onCreate, onRetry }: GroupsStateProps) {
  const { t } = useTranslation();

  if (kind === 'loading') {
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('accountGroups.loading')}</h2>
      </section>
    );
  }

  if (kind === 'error' || kind === 'unauthorized') {
    return (
      <section className={`card ${styles.state}`} role="alert">
        <h2>
          {t(
            kind === 'unauthorized'
              ? 'accountGroups.unauthorized.title'
              : 'accountGroups.error.title',
          )}
        </h2>
        <p>
          {t(
            kind === 'unauthorized'
              ? 'accountGroups.unauthorized.description'
              : 'accountGroups.error.description',
          )}
        </p>
        {kind === 'error' ? (
          <button className="secondary-action" onClick={onRetry} type="button">
            {t('foundation.retry')}
          </button>
        ) : null}
      </section>
    );
  }

  return (
    <section className={`card ${styles.state}`}>
      <h2>
        {t(includeArchived ? 'accountGroups.emptyArchived.title' : 'accountGroups.empty.title')}
      </h2>
      <p>
        {t(
          includeArchived
            ? 'accountGroups.emptyArchived.description'
            : 'accountGroups.empty.description',
        )}
      </p>
      {!includeArchived ? (
        <button className="primary-action" onClick={onCreate} type="button">
          {t('accountGroups.addFirst')}
        </button>
      ) : null}
    </section>
  );
}
