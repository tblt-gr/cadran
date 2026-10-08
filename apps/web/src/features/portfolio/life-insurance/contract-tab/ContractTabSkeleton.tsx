import { TaxTrackingCard } from './tax-tracking-card/TaxTrackingCard';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ContractCard } from '@/features/portfolio/life-insurance/contract-card/ContractCard';
import { FeeTermsCard } from '@/features/portfolio/life-insurance/fee-terms-card/FeeTermsCard';
import { EuroFundRatesCard } from '@/features/portfolio/life-insurance/euro-fund-rates-card/EuroFundRatesCard';
import styles from './ContractTab.module.css';
const noop = () => undefined;
export function ContractTabSkeleton() {
  return (
    <div className={styles.tab} aria-busy="true" data-testid="contract-skeleton">
      <SkeletonStatus>Chargement du contrat…</SkeletonStatus>
      <ContractCard onEdit={noop} />
      <TaxTrackingCard summary={null} />
      <FeeTermsCard loading onAdd={noop} onRemove={noop} />
      <EuroFundRatesCard loading lastClosedYear={0} onAdd={noop} onRemove={noop} />
    </div>
  );
}
