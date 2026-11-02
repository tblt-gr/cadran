import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

export function accountOperationsActive() {
  const params = new URLSearchParams(window.location.search);
  return params.get('tab') === 'operations' || params.has('month');
}

export function AccountNavigation({
  accountId,
  operations,
}: {
  accountId: string;
  operations: boolean;
}) {
  return (
    <TabsNav
      items={[
        {
          id: 'account',
          label: 'Compte',
          active: !operations,
          href: `/accounts/${accountId}?tab=account`,
        },
        {
          id: 'operations',
          label: 'Opérations',
          active: operations,
          href: `/accounts/${accountId}?tab=operations`,
        },
      ]}
      label="Sections du compte"
    />
  );
}
