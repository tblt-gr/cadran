import { useTranslation } from 'react-i18next';
import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { ContractFigures } from './contract-figures/ContractFigures';
import { LifeInsuranceOverviewSkeleton } from './LifeInsuranceOverviewSkeleton';
export function LifeInsuranceOverview(props: InsuranceReportProps & { embedded?: boolean }) {
  const { t } = useTranslation();
  return (
    <LifeInsuranceSummaryPanel
      {...props}
      title={t('portfolio.lifeInsurance.overview.unavailable')}
      announceLoading={false}
    >
      {(summary) =>
        summary ? (
          <ContractFigures
            embedded={props.embedded}
            summary={summary}
            accountId={props.accountId}
            sourceReturnFocus={props.sourceReturnFocus}
          />
        ) : (
          <LifeInsuranceOverviewSkeleton embedded={props.embedded} />
        )
      }
    </LifeInsuranceSummaryPanel>
  );
}
