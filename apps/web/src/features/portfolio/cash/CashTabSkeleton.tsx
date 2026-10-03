import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { CashMovementsTable } from './cash-movements-table/CashMovementsTable';
import { CashSummary } from './cash-summary/CashSummary';
import { CashWarnings } from './cash-warnings/CashWarnings';
import { CashToolbar } from './CashToolbar';
import { startOfYear } from './cashPeriod';
import styles from './CashTab.module.css';

/** Cold-load layout of the Espèces tab: the real chrome, every figure pending. */
export function CashTabSkeleton({ accountId = '' }: { accountId?: string }) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);

  return (
    <div className={styles.tab}>
      <CashToolbar
        disabled
        from={startOfYear(today)}
        onChange={() => undefined}
        to={today}
        today={today}
      />
      <p className={styles.note}>
        Les montants sont saisis dans la devise du compte ; le multi-devises n’est pas pris en
        charge.
      </p>
      <div aria-busy="true" className={styles.tab} data-testid="cash-skeleton">
        <CashSummary cash={null} />
        <CashWarnings accountId={accountId} cash={null} />
        <CashMovementsTable cash={null} />
      </div>
    </div>
  );
}
