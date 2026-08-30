import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './PlaceholderPage.module.css';

export function PlaceholderPage() {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.page}`} aria-labelledby="placeholder-title">
      <div className={styles.mark}>
        <Icon name="menu" size={28} />
      </div>
      <h2 id="placeholder-title">{t('states.placeholder.title')}</h2>
      <p>{t('states.placeholder.description')}</p>
      <a className="secondary-action" href="/">
        {t('actions.backHome')}
      </a>
    </section>
  );
}
