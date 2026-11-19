import { useTranslation } from 'react-i18next';
import type { Account } from '@cadran/api-client';
import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { QualityIssues } from '@/features/portfolio/life-insurance/overview/quality-issues/QualityIssues';
import { ValuationsTab } from '@/features/portfolio/valuations/ValuationsTab';
import styles from './ReportTabs.module.css';
export function LifeInsuranceValuationsTab(
  props: InsuranceReportProps & {
    account: Account;
    recording?: boolean;
    onRecordClose?: () => void;
  },
) {
  const { t } = useTranslation();
  return (
    <div className={styles.tab}>
      <LifeInsuranceSummaryPanel
        {...props}
        title={t('portfolio.lifeInsurance.quality.unavailable')}
        announceLoading={false}
      >
        {(summary) => (
          <QualityIssues
            summary={summary}
            accountId={props.accountId}
            sourceReturnFocus={props.sourceReturnFocus}
          />
        )}
      </LifeInsuranceSummaryPanel>
      <ValuationsTab
        account={props.account}
        asOf={props.asOf}
        recording={props.recording}
        onRecordClose={props.onRecordClose}
        sourceReturnFocus={props.sourceReturnFocus}
      />
    </div>
  );
}
