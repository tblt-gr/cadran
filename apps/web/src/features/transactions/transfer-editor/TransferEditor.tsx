import type { Account, CreateTransferRequest, Transfer } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { TransferForm, type TransferFormDefaults } from './TransferForm';
import { useTransferSubmit } from './useTransferSubmit';
import formStyles from '@/features/transactions/transaction-form/TransactionForm.module.css';

interface TransferEditorProps {
  accounts: Account[];
  close: () => void;
  defaults?: TransferFormDefaults;
  onSubmit: (body: CreateTransferRequest) => Promise<Transfer>;
  onSaved: () => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  refreshError?: { message: string; retry: () => void };
}

export function TransferEditor({
  accounts,
  close,
  defaults,
  onSubmit,
  onSaved,
  pending,
  submitError,
  refreshError,
}: TransferEditorProps) {
  const { t } = useTranslation();
  const transfer = useTransferSubmit({ onSubmit, onSaved });

  return (
    <Modal
      close={() => {
        if (!transfer.pending && !pending) close();
      }}
      eyebrow={t('transactions.transfer.eyebrow')}
      title={t('transactions.transfer.title')}
    >
      {refreshError ? (
        <div className={formStyles.alert} role="alert">
          <p>{refreshError.message}</p>
          <button className="secondary-action" onClick={refreshError.retry} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      <TransferForm
        accounts={accounts}
        defaults={defaults}
        onSubmit={(body) => void transfer.submit(body)}
        pending={pending || transfer.pending}
        submitError={submitError}
      />
    </Modal>
  );
}
