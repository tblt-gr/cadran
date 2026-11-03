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
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  transactionErrorDetail,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';

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
  const { t } = useTranslation();
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
      intent={voiding ? 'confirm' : 'edit'}
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
      <TransferUpdateForm
        key={transfer?.id ?? 'loading'}
        transfer={transfer}
        voiding={voiding}
        onCancel={close}
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
