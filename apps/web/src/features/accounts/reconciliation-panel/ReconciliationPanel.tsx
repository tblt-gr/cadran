import {
  readAccountReconciliation,
  resolveAccountReconciliation,
  type Account,
  type AccountReconciliationResolution,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  accountRequestError,
  AccountRequestError,
  requestFailed,
} from '@/features/accounts/accountError';
import { PendingTransactionsTable } from './pending-transactions/PendingTransactionsTable';
import { ReconciliationFigures } from './reconciliation-figures/ReconciliationFigures';
import { ResolutionForm } from './resolution-form/ResolutionForm';
import styles from './ReconciliationPanel.module.css';

interface ReconciliationPanelProps {
  account: Account;
  onReconciled: () => void;
}

function defaultPeriodStart(account: Account): string {
  const closing = account.valuation.asOf ?? account.openedOn;
  const monthStart = `${closing.slice(0, 7)}-01`;

  return monthStart < account.openedOn ? account.openedOn : monthStart;
}

type FailureKey = 'forbidden' | 'stale' | 'conflict' | 'invalid' | 'network';

function failureKey(error: unknown): FailureKey {
  if (error instanceof AccountRequestError) {
    if (error.status === 403) {
      return 'forbidden';
    }
    if (error.kind === 'stale') {
      return 'stale';
    }
    if (error.kind === 'conflict') {
      return 'conflict';
    }
    if (error.kind === 'invalid') {
      return 'invalid';
    }
  }

  return 'network';
}

/**
 * Reconciles the account's latest observed balance with the movements of a
 * period. The server computes every figure; this panel shows them as strings
 * and sends back only the resolution the reader chose.
 */
export function ReconciliationPanel({ account, onReconciled }: ReconciliationPanelProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [periodStart, setPeriodStart] = useState(() => defaultPeriodStart(account));
  const snapshotId = account.valuation.snapshotId;
  const snapshotVersion = account.valuation.version;
  const closing = account.valuation.asOf;
  const startValid = periodStart !== '' && (closing === null || periodStart <= closing);

  const reading = useQuery({
    enabled: snapshotId !== null && startValid,
    queryKey: ['account-reconciliation', account.id, snapshotId, periodStart],
    queryFn: async ({ signal }) => {
      const result = await readAccountReconciliation({
        ...authApiOptions(),
        path: { accountId: account.id },
        query: { snapshotId: snapshotId ?? '', periodStart },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }

      return result.data;
    },
    retry: false,
  });

  const resolve = useMutation({
    mutationFn: async (resolution: AccountReconciliationResolution) => {
      const result = await withCsrfRetry(() =>
        resolveAccountReconciliation({
          ...authApiOptions(),
          headers: {
            ...authApiOptions().headers,
            // Stable per attempt: a retry after a lost response replays the first result.
            'Idempotency-Key': `reconcile:${snapshotId}:${snapshotVersion}:${periodStart}:${resolution}`,
          },
          path: { accountId: account.id },
          body: {
            snapshotId: snapshotId ?? '',
            snapshotVersion: snapshotVersion ?? 0,
            periodStart,
            resolution,
          },
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }

      return result.data;
    },
    onError: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
      await queryClient.invalidateQueries({ queryKey: ['account-reconciliation', account.id] });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
      await queryClient.invalidateQueries({ queryKey: ['transactions'] });
      onReconciled();
    },
  });

  if (snapshotId === null || snapshotVersion === null) {
    return <p className={styles.state}>{t('accounts.reconciliation.noSnapshot')}</p>;
  }

  return (
    <div className={styles.panel}>
      <label className={styles.period}>
        <span>{t('accounts.reconciliation.periodStart')}</span>
        <input
          aria-describedby="reconciliation-period-hint"
          aria-invalid={startValid ? undefined : true}
          max={closing ?? undefined}
          min={account.openedOn}
          onChange={(event) => setPeriodStart(event.target.value)}
          type="date"
          value={periodStart}
        />
      </label>
      <p className={styles.state} id="reconciliation-period-hint">
        {t('accounts.reconciliation.periodStartHint')}
      </p>

      {!startValid ? (
        <p className={styles.alert} role="alert">
          {t('accounts.reconciliation.errors.invalid')}
        </p>
      ) : reading.data === undefined && reading.isPending ? (
        <p className={styles.state} role="status">
          {t('accounts.reconciliation.loading')}
        </p>
      ) : reading.isError ? (
        <div className={styles.alert} role="alert">
          <p>{t(`accounts.reconciliation.errors.${failureKey(reading.error)}`)}</p>
          {failureKey(reading.error) === 'forbidden' ? null : (
            <button
              className="secondary-action"
              onClick={() => void reading.refetch()}
              type="button"
            >
              {t('accounts.reconciliation.retry')}
            </button>
          )}
        </div>
      ) : reading.data === undefined ? (
        <div className={styles.alert} role="alert">
          <p>{t(`accounts.reconciliation.errors.${failureKey(reading.error)}`)}</p>
        </div>
      ) : (
        <>
          {resolve.isError ? (
            <p className={styles.alert} role="alert">
              {t(`accounts.reconciliation.errors.${failureKey(resolve.error)}`)}
            </p>
          ) : null}
          <ReconciliationFigures reconciliation={reading.data} />
          <PendingTransactionsTable
            count={reading.data.pendingCount}
            transactions={reading.data.pendingTransactions}
          />
          <ResolutionForm
            key={reading.data.snapshotVersion}
            onSubmit={(resolution) => resolve.mutate(resolution)}
            pending={resolve.isPending}
            reconciliation={reading.data}
          />
        </>
      )}
    </div>
  );
}
