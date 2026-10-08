import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { RecordedAmounts } from '@/features/portfolio/life-insurance/overview/recorded-amounts/RecordedAmounts';
import { TaxTrackingCard } from './tax-tracking-card/TaxTrackingCard';
export function ContractReportingPanel(props: InsuranceReportProps) {
  return (
    <LifeInsuranceSummaryPanel
      {...props}
      title="Les montants du contrat sont indisponibles"
      announceLoading={false}
    >
      {(summary) => (
        <>
          <RecordedAmounts
            summary={summary}
            accountId={props.accountId}
            sourceReturnFocus={props.sourceReturnFocus}
          />
          <TaxTrackingCard summary={summary} />
        </>
      )}
    </LifeInsuranceSummaryPanel>
  );
}
