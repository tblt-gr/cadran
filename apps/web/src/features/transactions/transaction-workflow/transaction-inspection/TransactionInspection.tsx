import { getTransaction, type Transaction } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import {
  transactionErrorDetail,
  transactionErrorKind,
  transactionRequestError,
} from '@/features/transactions/transactionError';
import styles from './TransactionInspection.module.css';

export function TransactionInspection({
  transactionId,
  close,
}: {
  transactionId: string;
  close: () => void;
}) {
  const { t, i18n } = useTranslation();
  const query = useQuery({
    queryKey: ['transaction-inspection', transactionId],
    gcTime: 0,
    queryFn: async ({ signal }) => {
      const result = await getTransaction({
        ...authApiOptions(),
        path: { id: transactionId },
        signal,
      });
      if (!result.response?.ok || !result.data) throw transactionRequestError(result);
      return result.data;
    },
    retry: false,
  });
  return (
    <Modal close={close} title={t('transactions.workflow.details')}>
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
      <TransactionInspectionValues transaction={query.data ?? null} language={i18n.language} />
    </Modal>
  );
}

function TransactionInspectionValues({
  transaction,
  language,
}: {
  transaction: Transaction | null;
  language: string;
}) {
  const { t } = useTranslation();
  // The source label only has a row once the user renamed the transaction; it is reserved while loading.
  const sourceLabelShown =
    transaction === null || transaction.displayLabel !== transaction.rawLabel;
  const fields = [
    ['label', transaction?.displayLabel],
    ...(sourceLabelShown ? ([['rawLabel', transaction?.rawLabel]] as const) : []),
    ['bookedOn', transaction ? formatCalendarDay(transaction.bookedOn, language) : undefined],
    [
      'amount',
      transaction
        ? formatAmount(transaction.amount.value, transaction.amount.assetCode, language)
        : undefined,
    ],
    ['nature', transaction ? t(`transactions.natures.${transaction.nature}`) : undefined],
    ['state', transaction ? t(`transactions.states.${transaction.state}`) : undefined],
    ['counterparty', transaction?.counterparty],
    ['note', transaction?.note],
    ['bankReference', transaction?.bankReference],
  ] as const;
  return (
    <dl className={styles.details}>
      {fields.map(([field, value]) => (
        <div key={field}>
          <dt>{t(`transactions.fields.${field}`)}</dt>
          <dd>
            {transaction === null ? (
              <SkeletonBlock />
            ) : (
              (value ?? t('transactions.workflow.unspecified'))
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}
