import {
  recordAccountBalance,
  type Account,
  type RecordAccountBalanceRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import { useTranslation } from 'react-i18next';
import { SkeletonButton, SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useAccountDetail } from '@/features/accounts/account-detail/useAccountDetail';
import {
  accountErrorKind,
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from '@/features/accounts/accountError';
import { RecordBalanceForm } from '@/features/accounts/record-balance-form/RecordBalanceForm';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './MissingValuationRecorder.module.css';

interface MissingValuationRecorderProps {
  accountId: string;
  onBack: () => void;
  onNavigate: () => void;
  onRecorded: () => void;
}

/**
 * Records the missing balance of one account without leaving the dashboard.
 * The account is read fresh: the form needs its unit, opening day and the
 * version of the day's snapshot, none of which the net worth aggregate carries.
 */
export function MissingValuationRecorder({
  accountId,
  onBack,
  onNavigate,
  onRecorded,
}: MissingValuationRecorderProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const account = useAccountDetail(accountId);
  const href = `/accounts/${accountId}`;

  const record = useMutation({
    mutationFn: async ({
      target,
      body,
    }: {
      target: Account;
      body: RecordAccountBalanceRequest;
    }) => {
      const result = await withCsrfRetry(() =>
        recordAccountBalance({ ...authApiOptions(), path: { id: target.id }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: async (error) => {
      if (error instanceof AccountRequestError && error.kind === 'stale') {
        await queryClient.invalidateQueries({ queryKey: ['account', accountId] });
      }
    },
    onSuccess: async () => {
      onRecorded();
      await invalidateAccountBalances(queryClient);
    },
  });

  const back = (
    <button className="secondary-action" onClick={onBack} type="button">
      {t('dashboard.missingValuations.back')}
    </button>
  );
  const openAccount = (
    <a
      className="secondary-action"
      href={href}
      onClick={(event) => {
        if (handleClientNavigation(event, href)) onNavigate();
      }}
    >
      {t('dashboard.missingValuations.openAccount')}
    </a>
  );

  if (account.isPending) {
    return (
      <div className={styles.recorder}>
        <SkeletonStatus>{t('dashboard.missingValuations.loading')}</SkeletonStatus>
        <div className={styles.fields} aria-hidden="true">
          <SkeletonField label={t('accounts.balances.fields.asOf')} />
          <SkeletonField label={t('accounts.balances.fields.amount')} />
        </div>
        <SkeletonField label={t('accounts.balances.fields.comment')} />
        <div className={styles.actions}>
          {back}
          <SkeletonButton />
        </div>
      </div>
    );
  }

  if (account.isError) {
    return (
      <div className={styles.recorder}>
        <p className={styles.notice} role="alert">
          {t('dashboard.missingValuations.loadError')}
        </p>
        <div className={styles.actions}>
          {back}
          {openAccount}
        </div>
      </div>
    );
  }

  if (!account.data.editable || account.data.valuationMode === 'PORTFOLIO') {
    return (
      <div className={styles.recorder}>
        <p className={styles.notice}>{t('dashboard.missingValuations.notRecordable')}</p>
        <div className={styles.actions}>
          {back}
          {openAccount}
        </div>
      </div>
    );
  }

  return (
    <div className={styles.recorder}>
      <RecordBalanceForm
        account={account.data}
        onCancel={onBack}
        onCorrected={onRecorded}
        onSubmit={(body) => record.mutate({ target: account.data, body })}
        pending={record.isPending}
        submitError={accountErrorKind(record.error, record.isError)}
        valuation={account.data.valuation}
      />
      <div className={styles.actions}>{back}</div>
    </div>
  );
}
