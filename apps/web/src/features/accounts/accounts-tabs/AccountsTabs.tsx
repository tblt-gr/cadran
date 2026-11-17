import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

type AccountsTabPath = '/accounts' | '/accounts/groups';

/** Sub-navigation of the two account pages: the accounts themselves, then the groups that gather them. */
export function AccountsTabs({ path }: { path: AccountsTabPath }) {
  const { t } = useTranslation();
  const tabs = [
    { href: '/accounts', label: t('accounts.tabs.accounts') },
    { href: '/accounts/groups', label: t('accounts.tabs.groups') },
  ];

  return (
    <TabsNav
      items={tabs.map((tab) => ({
        id: tab.href,
        active: path === tab.href,
        href: tab.href,
        label: tab.label,
      }))}
      label={t('accounts.tabs.label')}
    />
  );
}
