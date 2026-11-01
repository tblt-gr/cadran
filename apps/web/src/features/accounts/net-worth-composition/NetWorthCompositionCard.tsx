import { useTranslation } from 'react-i18next';
import { NetWorthCompositionView } from './NetWorthCompositionView';
import { useNetWorthComposition } from './useNetWorthComposition';
import styles from './NetWorthCompositionCard.module.css';

/** `Répartition par compte`: the whole eligible net worth split by account, above the group tables. */
export function NetWorthCompositionCard({ asOf }: { asOf: string }) {
  const { t } = useTranslation();
  const composition = useNetWorthComposition(asOf);

  if (composition.isError) {
    return (
      <section className={`card ${styles.card}`}>
        <h2 className={styles.title}>{t('accounts.overviewComposition.title')}</h2>
        <div className={styles.error} role="alert">
          <p>{t('accounts.overviewComposition.error')}</p>
          <button
            className="secondary-action"
            onClick={() => void composition.refetch()}
            type="button"
          >
            {t('accounts.overviewComposition.retry')}
          </button>
        </div>
      </section>
    );
  }

  // The page empty state already covers a workspace without eligible account.
  if (composition.data?.accounts.length === 0) {
    return null;
  }

  return <NetWorthCompositionView composition={composition.data ?? null} />;
}
