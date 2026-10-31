import type { ReactNode } from 'react';
import type { TransactionNature } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { OperationsToolbar } from '@/features/accounts/operations-toolbar/OperationsToolbar';
import { MultiSelect } from '@/components/ui/multi-select/MultiSelect';
import type { AccountMovementFilters } from '@/features/accounts/account-detail/account-movements/accountMovementFilters';
const natures: TransactionNature[] = [
  'INCOME',
  'EXPENSE',
  'TRANSFER',
  'REFUND',
  'FEE',
  'ADJUSTMENT',
];

export function AccountOperationFilters({
  value,
  onChange,
  disabled = false,
  actions,
}: {
  value: AccountMovementFilters;
  onChange: (value: AccountMovementFilters) => void;
  disabled?: boolean;
  actions?: ReactNode;
}) {
  const { t } = useTranslation();
  return (
    <OperationsToolbar
      value={value}
      onChange={(next) => onChange({ ...value, ...next })}
      disabled={disabled}
      actions={actions}
      types={
        <MultiSelect
          label="Types d’opération"
          value={value.nature}
          onChange={(nature) => onChange({ ...value, nature })}
          options={natures.map((nature) => ({
            value: nature,
            label: t(`transactions.natures.${nature}`),
          }))}
        />
      }
    />
  );
}
