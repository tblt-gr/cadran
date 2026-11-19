import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ContractFigures } from './contract-figures/ContractFigures';
import styles from './LifeInsuranceOverview.module.css';
export function LifeInsuranceOverviewSkeleton({ embedded = false }: { embedded?: boolean }) {
  const { t } = useTranslation();
  return (
    <div
      className={styles.overview}
      aria-busy="true"
      data-testid="life-insurance-overview-skeleton"
    >
      <SkeletonStatus>{t('portfolio.lifeInsurance.overview.loading')}</SkeletonStatus>
      <ContractFigures embedded={embedded} summary={null} accountId="" />
    </div>
  );
}
