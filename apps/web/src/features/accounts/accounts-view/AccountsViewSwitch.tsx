import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import type { AccountsView } from './accountsView';

/** All the accounts in one table, or one table per group: the same switch as the reports use. */
export function AccountsViewSwitch({
  onChange,
  value,
}: {
  onChange: (view: AccountsView) => void;
  value: AccountsView;
}) {
  const { t } = useTranslation();

  return (
    <SegmentedControl
      label={t('accounts.view.label')}
      onChange={onChange}
      options={[
        { value: 'grouped', label: t('accounts.view.grouped') },
        { value: 'all', label: t('accounts.view.all') },
      ]}
      value={value}
    />
  );
}
