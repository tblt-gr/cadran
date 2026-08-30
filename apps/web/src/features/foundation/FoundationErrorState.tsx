import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './FoundationStates.module.css';

export function FoundationErrorState({ retry }: { retry: () => void }) {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.state}`} aria-labelledby="error-title">
      <div className={`${styles.mark} ${styles.errorMark}`}>
        <Icon name="alert" size={24} />
      </div>
      <div role="alert">
        <h2 id="error-title">{t('states.error.title')}</h2>
        <p>{t('states.error.description')}</p>
      </div>
      <button className="secondary-action" onClick={retry} type="button">
        {t('foundation.retry')}
      </button>
    </section>
  );
}
