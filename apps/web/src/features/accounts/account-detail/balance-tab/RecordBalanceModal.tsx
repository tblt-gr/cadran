import type { Account, AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { accountErrorKind } from '@/features/accounts/accountError';
import { RecordBalanceForm } from '@/features/accounts/record-balance-form/RecordBalanceForm';
import { useRecordBalance } from './useRecordBalance';

/** The existing record form in its modal, started blank or in correction mode on one snapshot. */
export function RecordBalanceModal({
  account,
  correcting,
  initialAsOf,
  close,
}: {
  account: Account;
  correcting?: AccountBalanceSnapshot;
  /** Day the form opens on, for a balance asked for a given month end. */
  initialAsOf?: string;
  close: () => void;
}) {
  const { t } = useTranslation();
  const record = useRecordBalance(account.id, close);

  return (
    <Modal
      intent={correcting ? 'edit' : 'create'}
      close={close}
      title={t('accounts.balances.title', { label: account.label })}
    >
      <RecordBalanceForm
        account={account}
        initialAsOf={initialAsOf}
        initialEditing={correcting}
        onCancel={close}
        onCorrected={close}
        onSubmit={(body) => record.mutate(body)}
        pending={record.isPending}
        submitError={accountErrorKind(record.error, record.isError)}
        valuation={account.valuation}
      />
    </Modal>
  );
}
