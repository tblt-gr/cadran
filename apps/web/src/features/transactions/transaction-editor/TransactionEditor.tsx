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
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  transaction?: Transaction;
}

export function TransactionEditor({
  accounts,
  close,
  defaults,
  onSubmit,
  pending,
  submitError,
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
        key={creating ? 'create' : transaction.id}
        onSubmit={onSubmit}
        pending={pending}
        submitError={submitError}
        transaction={transaction}
      />
    </Modal>
  );
}
