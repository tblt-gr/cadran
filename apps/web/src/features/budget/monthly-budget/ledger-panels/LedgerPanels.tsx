import type {
  MonthlyLedger,
  MonthlyLedgerAccountRow,
  MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { compareDecimalString } from '@/lib/tableSort';
import { useTranslation } from 'react-i18next';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { BudgetCreationDraft } from '@/features/budget/monthly-budget/budget-creation-modals/BudgetCreationModals';
import { LedgerPanel } from '@/features/budget/monthly-budget/ledger-panel/LedgerPanel';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useMonthlyRecap } from '@/features/budget/monthly-budget/monthly-recap/useMonthlyRecap';
import { useUncategorizedCount } from '@/features/budget/monthly-budget/useUncategorizedCount';
import styles from './LedgerPanels.module.css';

interface LedgerPanelsProps {
  activeAccountIds: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  data: MonthlyLedger;
  expenseRowsPending?: boolean;
  language: string;
  month: string;
  onAxisChange: (axis: BudgetAxis | null) => void;
  onDraft: (draft: BudgetCreationDraft) => void;
  onEditTransaction: (transactionId: string) => void;
}

/** A row with only pending movements is still worth opening, though it holds no booked amount. */
function listed(row: { hasMovements: boolean; pendingCount: number }): boolean {
  return row.hasMovements || row.pendingCount > 0;
}

/** The queue of the month's uncategorized transactions, same filters as the reminder count. */
function uncategorizedHref(month: string) {
  return `/transactions?categorization=NONE&period=month&month=${month}`;
}

export function LedgerPanels({
  activeAccountIds,
  axis,
  data,
  expenseRowsPending = false,
  language,
  month,
  onAxisChange,
  onDraft,
  onEditTransaction,
}: LedgerPanelsProps) {
  const { t } = useTranslation();
  const recap = useMonthlyRecap(month);
  // Null while loading or on error: the reminder then renders nothing.
  const uncategorizedCount = useUncategorizedCount(month);
  const totalState = recap.isPending
    ? 'loading'
    : recap.isError || !recap.data
      ? 'unavailable'
      : 'ready';
  const common = {
    actionsAllowed: data.actionsAllowed,
    axis,
    language,
    month,
    onEditTransaction,
  };
  // A category with no movement this month has nothing to explain; accounts stay listed
  // because an account without a transfer is still a valid transfer target.
  const incomeRows = data.incomeCategories.filter(listed);
  const expenseRows = data.expenseCategories
    .filter(listed)
    .sort(
      (left, right) =>
        compareDecimalString(left.total.value, right.total.value, 'desc') ||
        left.label.localeCompare(right.label, language),
    );
  const accountRows = [...data.accounts].sort(
    (left, right) =>
      Number(listed(right)) - Number(listed(left)) ||
      left.label.localeCompare(right.label, language),
  );

  return (
    <div className={styles.ledger}>
      <div className={styles.statuses}>
        {data.pendingCount > 0 ? (
          <p>{t('budget.monthly.pending', { count: data.pendingCount })}</p>
        ) : null}
        {uncategorizedCount !== null && uncategorizedCount > 0 ? (
          <p>
            <a
              href={uncategorizedHref(month)}
              onClick={(event) => handleClientNavigation(event, uncategorizedHref(month))}
            >
              {t('budget.monthly.uncategorized', { count: uncategorizedCount })}
            </a>
          </p>
        ) : null}
        {!data.actionsAllowed ? (
          <p className={styles.closed} role="status">
            {t('budget.monthly.closedReason')}
          </p>
        ) : null}
      </div>

      <div className={styles.panels}>
        <LedgerPanel
          {...common}
          kind="income"
          onAddFree={() => onDraft({ kind: 'income', row: null })}
          onAdd={(row) => onDraft({ kind: 'income', row: row as MonthlyLedgerCategoryRow })}
          rows={incomeRows}
          total={recap.data?.totals.cashIncome}
          totalState={totalState}
        />
        <LedgerPanel
          {...common}
          kind="expense"
          onAddFree={() => onDraft({ kind: 'expense', row: null })}
          onAdd={(row) => onDraft({ kind: 'expense', row: row as MonthlyLedgerCategoryRow })}
          onAxisChange={onAxisChange}
          rows={expenseRows}
          rowsPending={expenseRowsPending}
          total={
            axis
              ? recap.data?.totals.expensesByAxis.find((candidate) => candidate.axis === axis)
              : recap.data?.totals.budgetExpenses
          }
          totalState={totalState}
        />
        <LedgerPanel
          {...common}
          activeAccountIds={activeAccountIds}
          kind="account"
          onAdd={(row) => onDraft({ kind: 'account', row: row as MonthlyLedgerAccountRow })}
          rows={accountRows}
          total={recap.data?.totals.netSavingsTransfers}
          totalState={totalState}
        />
      </div>
    </div>
  );
}
