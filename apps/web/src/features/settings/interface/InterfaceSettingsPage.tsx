import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { Toast } from '@/components/ui/toast/Toast';
import { BudgetRequestError } from '@/features/budget/budgetError';
import { InterfaceSettingsCard } from './InterfaceSettingsCard';
import { InterfaceSettingsPageSkeleton } from './InterfaceSettingsPageSkeleton';
import { useInterfacePreferences, useSaveInterfacePreferences } from './useInterfacePreferences';
import styles from './InterfaceSettingsPage.module.css';

/** Workspace display preferences, saved on the server so every device shows the same figures. */
export function InterfaceSettingsPage() {
  const { t } = useTranslation();
  const preferences = useInterfacePreferences();
  const save = useSaveInterfacePreferences();
  const [saved, setSaved] = useState(false);

  if (preferences.isPending) return <InterfaceSettingsPageSkeleton />;

  if (preferences.isError) {
    return (
      <div className={styles.page}>
        <SettingsTabs path="/parameters/interface" />
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t('settings.interface.error.title')}</h2>
          <p>{t('settings.interface.error.description')}</p>
          <button
            className="secondary-action"
            onClick={() => void preferences.refetch()}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        </section>
      </div>
    );
  }

  const change = (includeUpcoming: boolean) => {
    setSaved(false);
    save.mutate(
      { includeUpcoming, version: preferences.data.version },
      { onSuccess: () => setSaved(true) },
    );
  };
  const failure =
    save.error instanceof BudgetRequestError && save.error.kind === 'conflict'
      ? 'settings.interface.stale'
      : 'settings.interface.saveError';

  return (
    <div className={styles.page}>
      <SettingsTabs path="/parameters/interface" />
      {saved ? (
        <Toast onDismiss={() => setSaved(false)}>{t('settings.interface.saved')}</Toast>
      ) : null}
      {save.isError ? (
        <p className={styles.help} role="alert">
          {t(failure)}
        </p>
      ) : null}
      <InterfaceSettingsCard
        onChange={change}
        pending={save.isPending}
        preferences={preferences.data}
      />
    </div>
  );
}
