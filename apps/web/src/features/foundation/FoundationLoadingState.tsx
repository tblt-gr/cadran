import { useTranslation } from 'react-i18next';
import styles from './FoundationStates.module.css';

export function FoundationLoadingState() {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.state}`} aria-labelledby="loading-title">
      <div className={`${styles.mark} ${styles.loadingMark}`} aria-hidden="true" />
      <div>
        <h2 id="loading-title">{t('states.loading.title')}</h2>
        <p role="status">{t('foundation.loading')}</p>
      </div>
      <div className={styles.skeletonStack} aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
    </section>
  );
}
