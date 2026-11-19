import { useTranslation } from 'react-i18next';
import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { RecordedAmounts } from '@/features/portfolio/life-insurance/overview/recorded-amounts/RecordedAmounts';
import { TaxTrackingCard } from './tax-tracking-card/TaxTrackingCard';
export function ContractReportingPanel(props: InsuranceReportProps) {
  const { t } = useTranslation();
  return (
    <LifeInsuranceSummaryPanel
      {...props}
      title={t('portfolio.lifeInsurance.contract.amountsUnavailable')}
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
