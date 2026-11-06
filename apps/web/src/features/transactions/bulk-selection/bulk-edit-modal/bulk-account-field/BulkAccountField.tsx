import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { listActiveAccounts } from '@/features/transactions/transactionAccounts';

interface BulkAccountFieldProps {
  /** The single asset of the selection: only accounts holding it are offered. */
  assetCode: string | null;
  disabled: boolean;
  /** The accounts are read once the row is ticked. */
  enabled: boolean;
  onChange: (accountId: string) => void;
  value: string;
}

/** Target account of a move: active, not valued by a portfolio, and in the selection's asset. */
export function BulkAccountField({
  assetCode,
  disabled,
  enabled,
  onChange,
  value,
}: BulkAccountFieldProps) {
  const { t } = useTranslation();
  const accounts = useQuery({
    queryKey: ['transactions', 'bulk-move-accounts'],
    queryFn: ({ signal }) => listActiveAccounts(signal),
    enabled: enabled && assetCode !== null,
  });
  const options = (accounts.data?.items ?? []).filter(
    (account) => account.assetCode === assetCode && account.valuationMode !== 'PORTFOLIO',
  );

  return (
    <label>
      {t('transactions.bulk.move.targetAccount')}
      <select disabled={disabled} onChange={(event) => onChange(event.target.value)} value={value}>
        <option value="">{t('transactions.bulk.move.chooseAccount')}</option>
        {options.map((account) => (
          <option key={account.id} value={account.id}>
            {account.label}
          </option>
        ))}
      </select>
    </label>
  );
}
