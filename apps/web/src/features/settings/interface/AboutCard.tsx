import { useTranslation } from 'react-i18next';
import styles from './InterfaceSettingsPage.module.css';

/** The installed product version, so a bug report or an upgrade can name the exact release. */
export function AboutCard() {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.section}`} aria-labelledby="settings-about-title">
      <header>
        <h3 id="settings-about-title">{t('settings.about.title')}</h3>
      </header>
      <p className={styles.meta}>
        {t('settings.about.version')} <span>{__APP_VERSION__}</span>
      </p>
    </section>
  );
}
