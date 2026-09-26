import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './SettingsTabs.module.css';

interface SettingsTabsProps {
  path: string;
}

const tabs = [
  { href: '/settings/profile', labelKey: 'settings.tabs.profile' },
  { href: '/settings/metric-policy', labelKey: 'settings.tabs.metricPolicy' },
] as const;

/** Sub-navigation shared by every settings page. */
export function SettingsTabs({ path }: SettingsTabsProps) {
  const { t } = useTranslation();

  return (
    <nav aria-label={t('settings.tabs.label')} className={styles.tabs}>
      {tabs.map((tab) => (
        <a
          aria-current={path === tab.href ? 'page' : undefined}
          className={styles.tab}
          href={tab.href}
          key={tab.href}
          onClick={(event) => handleClientNavigation(event, tab.href)}
        >
          {t(tab.labelKey)}
        </a>
      ))}
    </nav>
  );
}
