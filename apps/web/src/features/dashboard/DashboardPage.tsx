import { useTranslation } from 'react-i18next';
import { AllocationPanel } from './allocation-panel/AllocationPanel';
import { DashboardMetrics } from './metrics/DashboardMetrics';
import { NetWorthCard } from './net-worth-card/NetWorthCard';
import styles from './DashboardPage.module.css';

export function DashboardPage({ apiVersion }: { apiVersion: string }) {
  const { t } = useTranslation();

  return (
    <>
      <div className={styles.grid}>
        <NetWorthCard />
        <AllocationPanel />
        <DashboardMetrics />
      </div>

      <p className="api-status sr-only" role="status">
        {t('foundation.readyWithVersion', { version: apiVersion })}
      </p>
    </>
  );
}
