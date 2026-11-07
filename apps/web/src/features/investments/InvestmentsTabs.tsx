import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

/** Sub-navigation shared by the two investments pages, like the settings tabs. */
export function InvestmentsTabs({ path }: { path: '/investments' | '/investments/instruments' }) {
  const { t } = useTranslation();
  const tabs = [
    { href: '/investments', label: t('investments.tabs.positions') },
    { href: '/investments/instruments', label: t('investments.tabs.instruments') },
  ];

  return (
    <TabsNav
      items={tabs.map((tab) => ({
        id: tab.href,
        active: path === tab.href,
        href: tab.href,
        label: tab.label,
      }))}
      label={t('investments.tabs.label')}
    />
  );
}
