import { useTranslation } from 'react-i18next';
import { AllocationPanel } from './allocation-panel/AllocationPanel';
import { DashboardState } from './dashboard-state/DashboardState';
import { DashboardMetrics } from './metrics/DashboardMetrics';
import {
  closeMissingValuations,
  useMissingValuationsOpen,
} from './missing-valuations/missingValuationsDialog';
import { MissingValuationsModal } from './missing-valuations/MissingValuationsModal';
import { useNetWorthPeriod } from './net-worth-period/useNetWorthPeriod';
import { NetWorthCard } from './net-worth-card/NetWorthCard';
import { DashboardPageSkeleton } from './DashboardPageSkeleton';
import { netWorthErrorKind } from './net-worth/netWorthError';
import { useNetWorth, useNetWorthHistory } from './net-worth/useNetWorth';
import styles from './DashboardPage.module.css';

export function DashboardPage({ apiVersion }: { apiVersion?: string }) {
  const { t } = useTranslation();
  const netWorth = useNetWorth();
  const { period, changePeriod } = useNetWorthPeriod();
  const history = useNetWorthHistory(period);
  const missingValuationsOpen = useMissingValuationsOpen();
  const failure = netWorthErrorKind(netWorth.error, netWorth.isError);

  return (
    <>
      <div className={styles.grid}>
        {netWorth.isPending && netWorth.data === undefined ? (
          <DashboardPageSkeleton period={period} status={t('dashboard.netWorth.loading')} />
        ) : failure !== null || netWorth.data === undefined ? (
          <DashboardState
            kind={failure ?? 'error'}
            onRetry={() => {
              void netWorth.refetch();
              void history.refetch();
            }}
          />
        ) : (
          <>
            <NetWorthCard
              historyPending={history.isPending}
              historyFetching={history.isFetching}
              period={period}
              onPeriodChange={changePeriod}
              truncated={history.data?.truncated ?? false}
              historyPoints={history.data?.points ?? null}
              netWorth={netWorth.data}
            />
            <AllocationPanel netWorth={netWorth.data} />
          </>
        )}
        <DashboardMetrics />
      </div>

      {missingValuationsOpen && netWorth.data ? (
        <MissingValuationsModal
          close={closeMissingValuations}
          contributions={netWorth.data.contributions}
        />
      ) : null}

      {apiVersion === undefined ? null : (
        <p className="api-status sr-only" role="status">
          {t('foundation.readyWithVersion', { version: apiVersion })}
        </p>
      )}
    </>
  );
}
