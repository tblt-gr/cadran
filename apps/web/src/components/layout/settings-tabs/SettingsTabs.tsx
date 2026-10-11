import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

interface SettingsTabsProps {
  path: string;
}

const tabs = [
  { href: '/parameters/profile', labelKey: 'settings.tabs.profile' },
  { href: '/parameters/metric-policy', labelKey: 'settings.tabs.metricPolicy' },
  { href: '/parameters/catalog', labelKey: 'settings.tabs.catalog' },
  { href: '/parameters/product-models', labelKey: 'settings.tabs.productModels' },
] as const;

/** Sub-navigation shared by every settings page. */
export function SettingsTabs({ path }: SettingsTabsProps) {
  const { t } = useTranslation();

  return (
    <TabsNav
      items={tabs.map((tab) => ({
        id: tab.href,
        active: path === tab.href,
        href: tab.href,
        label: t(tab.labelKey),
      }))}
      label={t('settings.tabs.label')}
    />
  );
}
