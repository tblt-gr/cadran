import type {
  Account,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import {
  TransactionForm,
  type TransactionFormDefaults,
} from '@/features/transactions/transaction-form/TransactionForm';

interface TransactionEditorProps {
  accounts: Account[];
  close: () => void;
  defaults?: TransactionFormDefaults;
  defaultAccountId?: string;
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  submitErrorDetail?: string | null;
  transaction?: Transaction;
}

export function TransactionEditor({
  accounts,
  close,
  defaults,
  defaultAccountId,
  onSubmit,
  pending,
  submitError,
  submitErrorDetail,
  transaction,
}: TransactionEditorProps) {
  const { t } = useTranslation();
  const creating = transaction === undefined;

  return (
    <Modal
      close={close}
      eyebrow={t(creating ? 'transactions.form.createEyebrow' : 'transactions.form.editEyebrow')}
      title={t(creating ? 'transactions.form.createTitle' : 'transactions.form.editTitle')}
    >
      <TransactionForm
        accounts={accounts}
        defaults={defaults}
        defaultAccountId={defaultAccountId}
        key={creating ? 'create' : transaction.id}
        onSubmit={onSubmit}
        pending={pending}
        submitError={submitError}
        submitErrorDetail={submitErrorDetail}
        transaction={transaction}
      />
    </Modal>
  );
}
