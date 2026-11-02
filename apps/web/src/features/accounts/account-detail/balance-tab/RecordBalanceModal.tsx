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
  close,
}: {
  account: Account;
  correcting?: AccountBalanceSnapshot;
  close: () => void;
}) {
  const { t } = useTranslation();
  const record = useRecordBalance(account.id, close);

  return (
    <Modal close={close} title={t('accounts.balances.title', { label: account.label })}>
      <RecordBalanceForm
        account={account}
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
