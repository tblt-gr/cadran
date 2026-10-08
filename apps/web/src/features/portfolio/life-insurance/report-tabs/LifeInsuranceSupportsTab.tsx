import {
  LifeInsuranceSummaryPanel,
  type InsuranceReportProps,
} from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { SupportsTable } from '@/features/portfolio/life-insurance/overview/supports-table/SupportsTable';
import { PositionsTab } from '@/features/portfolio/portfolio-account/positions/PositionsTab';
import styles from './ReportTabs.module.css';
export function LifeInsuranceSupportsTab(props: InsuranceReportProps) {
  return (
    <div className={styles.tab}>
      <LifeInsuranceSummaryPanel
        {...props}
        title="Les supports du contrat sont indisponibles"
        announceLoading={false}
      >
        {(summary) => <SupportsTable summary={summary} />}
      </LifeInsuranceSummaryPanel>
      <section>
        <h3>Positions issues des opérations</h3>
        <p>
          Le registre des opérations conserve les quantités, les lots et les contrôles d’ordre. Les
          supports ci-dessus décrivent la valorisation observée.
        </p>
        <PositionsTab accountId={props.accountId} asOf={props.asOf} />
      </section>
    </div>
  );
}
