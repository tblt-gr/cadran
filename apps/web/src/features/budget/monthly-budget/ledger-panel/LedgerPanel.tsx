import type {
  MonthlyLedgerAccountRow,
  MonthlyLedgerCategoryRow,
  MonthlyRecapMetric,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { usePersistedToggle } from '@/hooks/use-persisted-toggle';
import { type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { LedgerRow, type LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { LedgerRowsPlaceholder } from '@/features/budget/monthly-budget/MonthlyBudgetPageSkeleton';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './LedgerPanel.module.css';
import { LedgerPanelHeader } from './LedgerPanelHeader';

const EMPTY_ACCOUNTS_KEY = 'cadran.budget.showEmptyTransferAccounts';

interface LedgerPanelProps {
  actionsAllowed: boolean;
  activeAccountIds?: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  onAdd: (row: MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow) => void;
  /** Adds with no row: the only way in when a category panel lists nothing. */
  onAddFree?: () => void;
  onAxisChange?: (axis: BudgetAxis | null) => void;
  onEditTransaction?: (transactionId: string) => void;
  rows: Array<MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow>;
  rowsPending?: boolean;
  total?: MonthlyRecapMetric;
  totalState: 'loading' | 'ready' | 'unavailable';
}

export function LedgerPanel({
  actionsAllowed,
  activeAccountIds = null,
  axis,
  kind,
  language,
  month,
  onAdd,
  onAddFree,
  onAxisChange,
  onEditTransaction,
  rows,
  rowsPending = false,
  total,
  totalState,
}: LedgerPanelProps) {
  const { t } = useTranslation();
  const titleId = `monthly-ledger-${kind}`;
  const [showEmptyAccounts, changeShowEmptyAccounts] = usePersistedToggle(EMPTY_ACCOUNTS_KEY);
  const visibleRows =
    kind === 'account' && !showEmptyAccounts ? rows.filter((row) => row.hasMovements) : rows;

  return (
    <section aria-labelledby={titleId} className={styles.panel}>
      <LedgerPanelHeader
        actionsAllowed={actionsAllowed}
        axis={axis}
        kind={kind}
        onAddFree={onAddFree}
        onAxisChange={onAxisChange}
        onShowEmptyAccountsChange={changeShowEmptyAccounts}
        showEmptyAccounts={showEmptyAccounts}
        titleId={titleId}
        total={total}
        totalState={totalState}
      />

      {rowsPending ? (
        <>
          <SkeletonStatus>{t('budget.monthly.loading')}</SkeletonStatus>
          <LedgerRowsPlaceholder />
        </>
      ) : visibleRows.length === 0 ? (
        <p className={styles.empty}>{t('budget.monthly.empty')}</p>
      ) : (
        <ul className={styles.rows}>
          {visibleRows.map((row) => (
            <LedgerRow
              actionsAllowed={actionsAllowed}
              addUnavailable={
                kind === 'account' && activeAccountIds !== null && !activeAccountIds.has(row.id)
              }
              axis={axis}
              key={row.id}
              kind={kind}
              language={language}
              month={month}
              onAdd={() => onAdd(row)}
              onEditTransaction={onEditTransaction}
              row={row}
            />
          ))}
        </ul>
      )}
    </section>
  );
}
