import type { Account, CreateTransferRequest } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { TransferForm } from './TransferForm';

interface TransferEditorProps {
  accounts: Account[];
  close: () => void;
  onSubmit: (body: CreateTransferRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
}

export function TransferEditor({
  accounts,
  close,
  onSubmit,
  pending,
  submitError,
}: TransferEditorProps) {
  const { t } = useTranslation();

  return (
    <Modal
      close={close}
      eyebrow={t('transactions.transfer.eyebrow')}
      title={t('transactions.transfer.title')}
    >
      <TransferForm
        accounts={accounts}
        onSubmit={onSubmit}
        pending={pending}
        submitError={submitError}
      />
    </Modal>
  );
}
