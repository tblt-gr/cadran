import { useTranslation } from 'react-i18next';
import styles from './RuleAccountsNotice.module.css';

interface RuleAccountsNoticeProps {
  isError: boolean;
  isPending: boolean;
  onRetry: () => void;
  unauthorized: boolean;
}

/**
 * Reports the availability of the account scope every rule edit depends on.
 * Rendered above the rule list rather than inside the editor, so a caller
 * knows before opening it whether editing is even possible.
 */
export function RuleAccountsNotice({
  isError,
  isPending,
  onRetry,
  unauthorized,
}: RuleAccountsNoticeProps) {
  const { t } = useTranslation();

  if (isPending) {
    return (
      <section className={styles.referencesState} role="status">
        <p>{t('categorizationRules.references.loading')}</p>
      </section>
    );
  }

  if (!isError) return null;

  return (
    <section className={`${styles.referencesState} ${styles.referencesError}`} role="alert">
      <div>
        <h3>
          {t(
            unauthorized
              ? 'categorizationRules.references.unauthorized.title'
              : 'categorizationRules.references.error.title',
          )}
        </h3>
        <p>
          {t(
            unauthorized
              ? 'categorizationRules.references.unauthorized.description'
              : 'categorizationRules.references.error.description',
          )}
        </p>
      </div>
      {!unauthorized ? (
        <button className="secondary-action" onClick={onRetry} type="button">
          {t('foundation.retry')}
        </button>
      ) : null}
    </section>
  );
}
