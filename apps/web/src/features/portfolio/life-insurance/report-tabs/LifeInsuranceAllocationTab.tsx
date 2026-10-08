import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { LifeInsuranceAllocationCard } from '@/features/portfolio/life-insurance/overview/allocation-card/LifeInsuranceAllocationCard';
import { AllocationTab } from '@/features/portfolio/portfolio-account/allocation-tab/AllocationTab';
import styles from './ReportTabs.module.css';
export function LifeInsuranceAllocationTab(props: InsuranceReportProps) {
  return (
    <div className={styles.tab}>
      <LifeInsuranceSummaryPanel
        {...props}
        title="La répartition du contrat est indisponible"
        announceLoading={false}
      >
        {(summary) => <LifeInsuranceAllocationCard summary={summary} />}
      </LifeInsuranceSummaryPanel>
      <AllocationTab {...props} title="Répartition par support, classe ou devise" />
    </div>
  );
}
