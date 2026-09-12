import {
  createTransactionRefund,
  getTransactionRefundable,
  type Account,
  type CreateRefundRequest,
  type Transaction,
} from '@cadran/api-client';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  transactionErrorDetail,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import { RefundForm } from './RefundForm';

interface RefundEditorProps {
  accounts: Account[];
  close: () => void;
  onSaved: () => Promise<void>;
  transaction: Transaction;
}

/** Reads the server-owned proposal before opening the pure refund form. */
export function RefundEditor({ accounts, close, onSaved, transaction }: RefundEditorProps) {
  const { t } = useTranslation();
  const refundable = useQuery({
    queryKey: ['transaction-refundable', transaction.id],
    queryFn: async ({ signal }) => {
      const result = await getTransactionRefundable({
        ...authApiOptions(),
        path: { id: transaction.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const save = useMutation({
    mutationFn: async (body: CreateRefundRequest) => {
      const result = await withCsrfRetry(() =>
        createTransactionRefund({ ...authApiOptions(), path: { id: transaction.id }, body }),
      );
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      await onSaved();
      close();
    },
  });

  return (
    <Modal
      close={close}
      eyebrow={t('transactions.refund.eyebrow')}
      title={t('transactions.refund.title')}
    >
      {refundable.isPending ? <p role="status">{t('transactions.refund.loading')}</p> : null}
      {refundable.isError ? <p role="alert">{t('transactions.refund.error')}</p> : null}
      {refundable.data ? (
        <RefundForm
          // Remounts when a fresher proposal lands, so the form never seeds
          // its amount and splits from a balance a just-created refund has
          // already consumed.
          key={refundable.dataUpdatedAt}
          accounts={accounts}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          proposal={refundable.data}
          submitError={transactionErrorKind(save.error, save.isError)}
          submitErrorDetail={transactionErrorDetail(save.error)}
        />
      ) : null}
    </Modal>
  );
}
