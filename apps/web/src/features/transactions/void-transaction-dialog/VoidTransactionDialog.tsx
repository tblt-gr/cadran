import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import styles from './VoidTransactionDialog.module.css';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';

interface VoidTransactionDialogProps {
  onConfirm: () => void;
  onCancel: () => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  submitErrorDay?: string | null;
  submitErrorDetail?: string | null;
  transaction: Transaction;
}

export function VoidTransactionDialog({
  onConfirm,
  onCancel,
  pending,
  submitError,
  submitErrorDay,
  submitErrorDetail,
  transaction,
}: VoidTransactionDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <TransactionSubmitError
          className={styles.alert}
          day={submitErrorDay}
          detail={submitErrorDetail}
          kind={submitError}
        />
      ) : null}
      <p>{t('transactions.void.description', { label: transaction.displayLabel })}</p>
      <p className={styles.hint}>{t('transactions.void.consequences')}</p>
      <ModalActions>
        <button className="secondary-action" onClick={onCancel} disabled={pending} type="button">
          {t('actions.cancel')}
        </button>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'transactions.void.confirming' : 'transactions.void.confirm')}
        </button>
      </ModalActions>
    </div>
  );
}
