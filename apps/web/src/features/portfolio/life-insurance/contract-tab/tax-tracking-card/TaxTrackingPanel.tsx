import { LifeInsuranceSummaryPanel } from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { TaxTrackingCard } from './TaxTrackingCard';
export function TaxTrackingPanel({ accountId, asOf }: { accountId: string; asOf: string }) {
  return (
    <LifeInsuranceSummaryPanel
      accountId={accountId}
      asOf={asOf}
      title="Le suivi fiscal est indisponible"
    >
      {(summary) => <TaxTrackingCard summary={summary} />}
    </LifeInsuranceSummaryPanel>
  );
}
