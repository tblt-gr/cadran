import { useTranslation } from 'react-i18next';
import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { LifeInsuranceAllocationCard } from '@/features/portfolio/life-insurance/overview/allocation-card/LifeInsuranceAllocationCard';
import { SupportsTable } from '@/features/portfolio/life-insurance/overview/supports-table/SupportsTable';
import { PositionsTab } from '@/features/portfolio/portfolio-account/positions/PositionsTab';
import styles from './ReportTabs.module.css';

/**
 * The supports of the contract and how they split, both read from the observed valuation. The
 * quantities and lots replayed from the operations stay one click away for checking.
 */
export function LifeInsuranceSupportsTab(props: InsuranceReportProps) {
  const { t } = useTranslation();
  return (
    <div className={styles.tab}>
      <LifeInsuranceSummaryPanel
        {...props}
        title={t('portfolio.lifeInsurance.supports.unavailable')}
        announceLoading={false}
      >
        {(summary) => (
          <>
            <SupportsTable summary={summary} />
            <LifeInsuranceAllocationCard summary={summary} />
          </>
        )}
      </LifeInsuranceSummaryPanel>
      <details className={styles.ledger}>
        <summary>{t('portfolio.lifeInsurance.supports.ledger')}</summary>
        <PositionsTab accountId={props.accountId} asOf={props.asOf} />
      </details>
    </div>
  );
}
