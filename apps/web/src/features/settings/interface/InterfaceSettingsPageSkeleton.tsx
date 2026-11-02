import { useTranslation } from 'react-i18next';
import { SettingsTabs } from '@/components/layout/settings-tabs/SettingsTabs';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { InterfaceSettingsCard } from './InterfaceSettingsCard';
import styles from './InterfaceSettingsPage.module.css';

/** Mirrors InterfaceSettingsPage element for element: the card is real, its control disabled. */
export function InterfaceSettingsPageSkeleton() {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.page} data-testid="interface-settings-skeleton">
      <SkeletonStatus>{t('settings.interface.loading')}</SkeletonStatus>
      <SettingsTabs path="/parameters/interface" />
      <InterfaceSettingsCard preferences={null} />
    </div>
  );
}
