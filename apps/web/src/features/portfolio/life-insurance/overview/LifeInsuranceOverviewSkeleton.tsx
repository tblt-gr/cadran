import { RecordedAmounts } from './recorded-amounts/RecordedAmounts';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ContractFigures } from './contract-figures/ContractFigures';
import { LifeInsuranceAllocationCard } from './allocation-card/LifeInsuranceAllocationCard';
import { SupportsTable } from './supports-table/SupportsTable';
import { QualityIssues } from './quality-issues/QualityIssues';
import styles from './LifeInsuranceOverview.module.css';
export function LifeInsuranceOverviewSkeleton() {
  return (
    <div
      className={styles.overview}
      aria-busy="true"
      data-testid="life-insurance-overview-skeleton"
    >
      <SkeletonStatus>Chargement de la synthèse du contrat…</SkeletonStatus>
      <ContractFigures summary={null} accountId="" />
      <div className={styles.details}>
        <LifeInsuranceAllocationCard summary={null} />
        <QualityIssues summary={null} accountId="" />
      </div>
      <SupportsTable summary={null} />
      <RecordedAmounts summary={null} accountId="" />
    </div>
  );
}
