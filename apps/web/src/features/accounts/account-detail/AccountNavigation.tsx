import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

export type AccountTab = 'account' | 'balance' | 'operations';

export function activeAccountTab(): AccountTab {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'operations' || params.has('month')) return 'operations';
  return params.get('tab') === 'balance' ? 'balance' : 'account';
}

export function AccountNavigation({ accountId, tab }: { accountId: string; tab: AccountTab }) {
  return (
    <TabsNav
      items={[
        {
          id: 'account',
          label: 'Compte',
          active: tab === 'account',
          href: `/accounts/${accountId}?tab=account`,
        },
        {
          id: 'operations',
          label: 'Opérations',
          active: tab === 'operations',
          href: `/accounts/${accountId}?tab=operations`,
        },
        {
          id: 'balance',
          label: 'Solde',
          active: tab === 'balance',
          href: `/accounts/${accountId}?tab=balance`,
        },
      ]}
      label="Sections du compte"
    />
  );
}
