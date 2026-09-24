import type {
  MonthlyLedger,
  MonthlyLedgerAccountRow,
  MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { BudgetCreationDraft } from '@/features/budget/monthly-budget/budget-creation-modals/BudgetCreationModals';
import { LedgerPanel } from '@/features/budget/monthly-budget/ledger-panel/LedgerPanel';
import { useMonthlyRecap } from '@/features/budget/monthly-budget/monthly-recap/useMonthlyRecap';
import styles from './LedgerPanels.module.css';

interface LedgerPanelsProps {
  activeAccountIds: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  data: MonthlyLedger;
  language: string;
  month: string;
  onAxisChange: (axis: BudgetAxis | null) => void;
  onDraft: (draft: BudgetCreationDraft) => void;
  onEditTransaction: (transactionId: string) => void;
}

export function LedgerPanels({
  activeAccountIds,
  axis,
  data,
  language,
  month,
  onAxisChange,
  onDraft,
  onEditTransaction,
}: LedgerPanelsProps) {
  const { t } = useTranslation();
  const recap = useMonthlyRecap(month);
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
  const incomeRows = data.incomeCategories.filter((row) => row.hasMovements);
  const expenseRows = data.expenseCategories.filter((row) => row.hasMovements);

  return (
    <div className={styles.ledger}>
      <div className={styles.statuses}>
        {data.pendingCount > 0 ? (
          <p>{t('budget.monthly.pending', { count: data.pendingCount })}</p>
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
          total={recap.data?.totals.budgetExpenses}
          totalState={totalState}
        />
        <LedgerPanel
          {...common}
          activeAccountIds={activeAccountIds}
          kind="account"
          onAdd={(row) => onDraft({ kind: 'account', row: row as MonthlyLedgerAccountRow })}
          rows={data.accounts}
          total={recap.data?.totals.netSavingsTransfers}
          totalState={totalState}
        />
      </div>
    </div>
  );
}
