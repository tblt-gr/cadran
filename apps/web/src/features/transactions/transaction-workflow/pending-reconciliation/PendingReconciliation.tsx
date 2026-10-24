import { getTransaction, reconcileTransaction, type Transaction } from '@cadran/api-client';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import {
  transactionErrorDetail,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import styles from './PendingReconciliation.module.css';

export function PendingReconciliation({
  transactionId,
  close,
  onSaved,
}: {
  transactionId: string;
  close: () => void;
  onSaved: () => Promise<void>;
}) {
  const { t, i18n } = useTranslation();
  const [matchedId, setMatchedId] = useState('');
  const query = useQuery({
    queryKey: ['transaction-reconciliation', transactionId],
    gcTime: 0,
    queryFn: async ({ signal }) => {
      async function read(id: string) {
        const result = await getTransaction({ ...authApiOptions(), path: { id }, signal });
        if (!result.response?.ok || !result.data) throw transactionRequestError(result);
        return result.data;
      }
      const transaction = await read(transactionId);
      const candidates = await Promise.all(transaction.reconciliationCandidateIds.map(read));
      return {
        transaction,
        candidates: candidates.filter(
          (candidate) =>
            candidate.id !== transaction.id &&
            candidate.accountId === transaction.accountId &&
            candidate.state === 'PENDING' &&
            candidate.reviewReason === null &&
            candidate.transferId === null,
        ),
      };
    },
    retry: false,
  });
  const mutation = useMutation({
    mutationFn: async (transaction: Transaction) => {
      const result = await withCsrfRetry(() =>
        reconcileTransaction({
          ...authApiOptions(),
          path: { id: transaction.id },
          body: { version: transaction.version, matchedTransactionId: matchedId || null },
        }),
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
    <Modal close={close} title={t('transactions.workflow.reconcileTitle')}>
      {query.isPending ? <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus> : null}
      {query.isError ? (
        <div role="alert">
          <p>
            {transactionErrorDetail(query.error) ??
              t(`transactions.errors.${transactionErrorKind(query.error, true) ?? 'network'}`)}
          </p>
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      <form
        className={styles.form}
        onSubmit={(event) => {
          event.preventDefault();
          if (query.data) mutation.mutate(query.data.transaction);
        }}
      >
        {mutation.isError ? (
          <p role="alert">
            {transactionErrorDetail(mutation.error) ??
              t(`transactions.errors.${transactionErrorKind(mutation.error, true) ?? 'network'}`)}
          </p>
        ) : null}
        <p>{query.data ? query.data.transaction.displayLabel : <SkeletonBlock />}</p>
        <label>
          <span>{t('transactions.workflow.match')}</span>
          <select
            data-autofocus
            disabled={!query.data || mutation.isPending}
            value={matchedId}
            onChange={(event) => setMatchedId(event.target.value)}
          >
            <option value="">{t('transactions.workflow.newMovement')}</option>
            {query.data?.candidates.map((candidate) => (
              <option value={candidate.id} key={candidate.id}>
                {candidate.displayLabel} · {formatCalendarDay(candidate.bookedOn, i18n.language)} ·{' '}
                {formatAmount(candidate.amount.value, candidate.amount.assetCode, i18n.language)}
              </option>
            ))}
          </select>
        </label>
        <p>{t('transactions.workflow.matchNotice')}</p>
        <button
          className="primary-action"
          type="submit"
          disabled={
            !query.data ||
            mutation.isPending ||
            query.data.transaction.state !== 'PENDING' ||
            query.data.transaction.reviewReason === null
          }
        >
          {t('transactions.workflow.confirmReconcile')}
        </button>
      </form>
    </Modal>
  );
}
