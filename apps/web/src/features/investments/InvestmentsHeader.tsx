import { InvestmentsTabs } from './InvestmentsTabs';
import styles from './InvestmentsPage.module.css';

/** Tab strip of the two investments pages; the title itself comes from the shell. */
export function InvestmentsHeader({ path }: { path: '/investments' | '/investments/instruments' }) {
  return (
    <header className={styles.header}>
      <InvestmentsTabs path={path} />
    </header>
  );
}
