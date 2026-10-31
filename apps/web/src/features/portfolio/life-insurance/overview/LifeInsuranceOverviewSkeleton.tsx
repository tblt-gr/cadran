import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ContractFigures } from './contract-figures/ContractFigures';
import styles from './LifeInsuranceOverview.module.css';
export function LifeInsuranceOverviewSkeleton({ embedded = false }: { embedded?: boolean }) {
  return (
    <div
      className={styles.overview}
      aria-busy="true"
      data-testid="life-insurance-overview-skeleton"
    >
      <SkeletonStatus>Chargement de la synthèse du contrat…</SkeletonStatus>
      <ContractFigures embedded={embedded} summary={null} accountId="" />
    </div>
  );
}
