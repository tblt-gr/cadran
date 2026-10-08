import type { Account } from '@cadran/api-client';
import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { QualityIssues } from '@/features/portfolio/life-insurance/overview/quality-issues/QualityIssues';
import { ValuationsTab } from '@/features/portfolio/valuations/ValuationsTab';
import styles from './ReportTabs.module.css';
export function LifeInsuranceValuationsTab(props: InsuranceReportProps & { account: Account }) {
  return (
    <div className={styles.tab}>
      <LifeInsuranceSummaryPanel
        {...props}
        title="La qualité des données est indisponible"
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
        sourceReturnFocus={props.sourceReturnFocus}
      />
    </div>
  );
}
