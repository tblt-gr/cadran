import type {
  MonthlyLedger,
  MonthlyLedgerAccountRow,
  MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { BudgetCreationDraft } from '@/features/budget/monthly-budget/budget-creation-modals/BudgetCreationModals';
import { LedgerPanel } from '@/features/budget/monthly-budget/ledger-panel/LedgerPanel';
import styles from './LedgerPanels.module.css';

interface LedgerPanelsProps {
  activeAccountIds: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  data: MonthlyLedger;
  language: string;
  month: string;
  onAxisChange: (axis: BudgetAxis | null) => void;
  onDraft: (draft: BudgetCreationDraft) => void;
}

export function LedgerPanels({
  activeAccountIds,
  axis,
  data,
  language,
  month,
  onAxisChange,
  onDraft,
}: LedgerPanelsProps) {
  const { t } = useTranslation();
  const common = { actionsAllowed: data.actionsAllowed, axis, language, month };

  return (
    <>
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
          onAdd={(row) => onDraft({ kind: 'income', row: row as MonthlyLedgerCategoryRow })}
          rows={data.incomeCategories}
        />
        <LedgerPanel
          {...common}
          kind="expense"
          onAdd={(row) => onDraft({ kind: 'expense', row: row as MonthlyLedgerCategoryRow })}
          onAxisChange={onAxisChange}
          rows={data.expenseCategories}
        />
        <LedgerPanel
          {...common}
          activeAccountIds={activeAccountIds}
          kind="account"
          onAdd={(row) => onDraft({ kind: 'account', row: row as MonthlyLedgerAccountRow })}
          rows={data.accounts}
        />
      </div>
    </>
  );
}
