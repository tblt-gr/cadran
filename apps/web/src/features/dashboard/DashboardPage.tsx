import { useTranslation } from 'react-i18next';
import { AllocationPanel } from './allocation-panel/AllocationPanel';
import { DashboardState } from './dashboard-state/DashboardState';
import { DashboardMetrics } from './metrics/DashboardMetrics';
import { NetWorthCard } from './net-worth-card/NetWorthCard';
import { netWorthErrorKind } from './net-worth/netWorthError';
import { useNetWorth, useNetWorthHistory } from './net-worth/useNetWorth';
import styles from './DashboardPage.module.css';

export function DashboardPage({ apiVersion }: { apiVersion: string }) {
  const { t } = useTranslation();
  const netWorth = useNetWorth();
  const history = useNetWorthHistory();
  const failure = netWorth.isPending
    ? 'loading'
    : netWorthErrorKind(netWorth.error, netWorth.isError);

  return (
    <>
      <div className={styles.grid}>
        {failure !== null || netWorth.data === undefined ? (
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
              historyPoints={history.data?.points ?? null}
              netWorth={netWorth.data}
            />
            <AllocationPanel netWorth={netWorth.data} />
          </>
        )}
        <DashboardMetrics />
      </div>

      <p className="api-status sr-only" role="status">
        {t('foundation.readyWithVersion', { version: apiVersion })}
      </p>
    </>
  );
}
