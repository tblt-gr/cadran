import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

export type AccountTab = 'account' | 'balance' | 'operations';

export function activeAccountTab(): AccountTab {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'operations' || params.has('month')) return 'operations';
  return params.get('tab') === 'balance' ? 'balance' : 'account';
}

export function AccountNavigation({ accountId, tab }: { accountId: string; tab: AccountTab }) {
  const { t } = useTranslation();
  return (
    <TabsNav
      items={[
        {
          id: 'account',
          label: t('accounts.detail.navigation.account'),
          active: tab === 'account',
          href: `/accounts/${accountId}?tab=account`,
        },
        {
          id: 'operations',
          label: t('accounts.detail.navigation.operations'),
          active: tab === 'operations',
          href: `/accounts/${accountId}?tab=operations`,
        },
        {
          id: 'balance',
          label: t('accounts.detail.navigation.balance'),
          active: tab === 'balance',
          href: `/accounts/${accountId}?tab=balance`,
        },
      ]}
      label={t('accounts.detail.navigation.label')}
    />
  );
}
