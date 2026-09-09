import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import styles from './VoidTransactionDialog.module.css';

interface VoidTransactionDialogProps {
  onConfirm: () => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  transaction: Transaction;
}

export function VoidTransactionDialog({
  onConfirm,
  pending,
  submitError,
  transaction,
}: VoidTransactionDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`transactions.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('transactions.void.description', { label: transaction.rawLabel })}</p>
      <p className={styles.hint}>{t('transactions.void.consequences')}</p>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'transactions.void.confirming' : 'transactions.void.confirm')}
        </button>
      </div>
    </div>
  );
}
