import {
  getTransfer,
  updateTransfer,
  voidTransfer,
  type UpdateTransferRequest,
} from '@cadran/api-client';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { TransferUpdateForm } from '@/features/transactions/transaction-workflow/transfer-update-form/TransferUpdateForm';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { formatAmount } from '@/lib/decimal';
import {
  transactionErrorDetail,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import styles from './TransferWorkflow.module.css';

export function TransferWorkflow({
  id,
  voiding,
  close,
  onSaved,
}: {
  id: string;
  voiding: boolean;
  close: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t, i18n } = useTranslation();
  const query = useQuery({
    queryKey: ['transfer-workflow', id],
    gcTime: 0,
    queryFn: async ({ signal }) => {
      const result = await getTransfer({ ...authApiOptions(), path: { id }, signal });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const mutation = useMutation({
    mutationFn: async (body: UpdateTransferRequest) => {
      const result = await withCsrfRetry(() =>
        voiding
          ? voidTransfer({ ...authApiOptions(), path: { id }, body: { version: body.version } })
          : updateTransfer({ ...authApiOptions(), path: { id }, body }),
      );
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      await onSaved();
      close();
    },
  });
  const transfer = query.data ?? null;
  return (
    <Modal
      close={close}
      title={t(
        voiding ? 'transactions.workflow.voidTransfer' : 'transactions.workflow.editTransfer',
      )}
    >
      {query.isPending ? <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus> : null}
      {query.isError ? (
        <div role="alert">
          <p>
            {transactionErrorDetail(query.error) ??
              t(`transactions.errors.${transactionErrorKind(query.error, true) ?? 'network'}`)}
          </p>
          <button className="secondary-action" onClick={() => void query.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      <dl className={styles.details}>
        {(['source', 'target', 'fee'] as const).map((leg) => (
          <div key={leg}>
            <dt>{t(`transactions.workflow.legs.${leg}`)}</dt>
            <dd>
              {transfer === null ? (
                <SkeletonBlock />
              ) : transfer[leg] ? (
                formatAmount(
                  transfer[leg].amount.value,
                  transfer[leg].amount.assetCode,
                  i18n.language,
                )
              ) : (
                t('transactions.workflow.unspecified')
              )}
            </dd>
          </div>
        ))}
      </dl>
      <TransferUpdateForm
        key={transfer?.id ?? 'loading'}
        transfer={transfer}
        voiding={voiding}
        pending={mutation.isPending}
        error={
          transactionErrorDetail(mutation.error) ??
          (mutation.isError
            ? t(`transactions.errors.${transactionErrorKind(mutation.error, true) ?? 'network'}`)
            : null)
        }
        onSubmit={(body) => mutation.mutate(body)}
      />
    </Modal>
  );
}
