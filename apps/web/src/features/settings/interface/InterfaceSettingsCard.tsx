import type { InterfacePreferences } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './InterfaceSettingsPage.module.css';

interface InterfaceSettingsCardProps {
  /** `null` while the preference loads: the static text and the control stay real, disabled. */
  preferences: InterfacePreferences | null;
  pending?: boolean;
  onChange?: (includeUpcoming: boolean) => void;
}

export function InterfaceSettingsCard({
  preferences,
  pending = false,
  onChange,
}: InterfaceSettingsCardProps) {
  const { t } = useTranslation();

  return (
    <section className={`card ${styles.section}`} aria-labelledby="settings-interface-title">
      <header>
        <h3 id="settings-interface-title">{t('settings.interface.title')}</h3>
      </header>
      <label className={styles.checkboxLabel}>
        <input
          aria-describedby="settings-interface-upcoming-help"
          checked={preferences?.includeUpcoming ?? false}
          disabled={preferences === null || pending}
          onChange={(event) => onChange?.(event.target.checked)}
          type="checkbox"
        />
        <span>{t('settings.interface.includeUpcoming.label')}</span>
      </label>
      <p className={styles.help} id="settings-interface-upcoming-help">
        {t('settings.interface.includeUpcoming.help')}
      </p>
    </section>
  );
}
