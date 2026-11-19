import { useTranslation } from 'react-i18next';
import { isLanguage, setLanguage } from '@/i18n';
import styles from './InterfaceSettingsPage.module.css';

/** Interface language, applied at once and remembered on this device. */
export function LanguageCard() {
  const { t, i18n } = useTranslation();

  return (
    <section className={`card ${styles.section}`} aria-labelledby="settings-language-title">
      <header>
        <h3 id="settings-language-title">{t('settings.language.title')}</h3>
      </header>
      <label className={styles.fieldLabel}>
        <span>{t('settings.language.label')}</span>
        <select
          onChange={(event) => {
            if (isLanguage(event.target.value)) {
              // A failed chunk load leaves the previous language active; the select follows it.
              setLanguage(event.target.value).catch(() => undefined);
            }
          }}
          value={i18n.language}
        >
          <option value="fr">Français</option>
          <option value="en">English</option>
        </select>
      </label>
    </section>
  );
}
