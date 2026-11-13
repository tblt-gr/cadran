import type { MonthlyLedger } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { BudgetCreationDraft } from '@/features/budget/monthly-budget/budget-creation-modals/BudgetCreationModals';
import { LedgerPanels } from '@/features/budget/monthly-budget/ledger-panels/LedgerPanels';
import { LedgerPanelsPlaceholder } from '@/features/budget/monthly-budget/ledger-panel/LedgerPanelsPlaceholder';
import { MonthlyRecap } from '@/features/budget/monthly-budget/monthly-recap/MonthlyRecap';
import { ExpenseDonut } from '@/features/budget/monthly-budget/expense-donut/ExpenseDonut';
import { UpcomingNotice } from '@/features/settings/interface/UpcomingNotice';
import styles from './MonthlyWorkspace.module.css';

/** Nullable data keeps the complete composition and its loading state in the same component. */
export function MonthlyWorkspace({
  ledger,
  month,
  axis,
  activeAccountIds,
  expenseRowsPending,
  onAxisChange,
  onDraft,
  onEditTransaction,
}: {
  ledger: MonthlyLedger | null;
  month: string;
  axis: BudgetAxis | null;
  activeAccountIds: ReadonlySet<string> | null;
  expenseRowsPending: boolean;
  onAxisChange: (axis: BudgetAxis | null) => void;
  onDraft: (draft: BudgetCreationDraft) => void;
  onEditTransaction: (id: string) => void;
}) {
  const { t, i18n } = useTranslation();
  return (
    <div
      className={styles.workspace}
      data-testid={ledger === null ? 'monthly-budget-skeleton' : undefined}
      aria-busy={ledger === null || undefined}
    >
      {ledger === null ? <SkeletonStatus>{t('budget.monthly.loading')}</SkeletonStatus> : null}
      <section aria-label={t('budget.followUp.details')} className={styles.section}>
        <UpcomingNotice
          includesUpcoming={ledger?.includesUpcoming ?? false}
          upcomingCount={ledger?.upcomingCount ?? 0}
        />
        {ledger ? (
          <LedgerPanels
            data={ledger}
            month={month}
            axis={axis}
            activeAccountIds={activeAccountIds}
            language={i18n.language}
            expenseRowsPending={expenseRowsPending}
            onAxisChange={onAxisChange}
            onDraft={onDraft}
            onEditTransaction={onEditTransaction}
          />
        ) : (
          <LedgerPanelsPlaceholder axis={axis} />
        )}
      </section>
      <section aria-label={t('budget.followUp.analysis')} className={styles.section}>
        <MonthlyRecap
          enabled={ledger !== null}
          announce={ledger !== null}
          month={month}
          leading={
            <ExpenseDonut
              axis={axis}
              period={{ from: ledger?.periodStart ?? '', to: ledger?.periodEnd ?? '' }}
              rows={ledger === null || expenseRowsPending ? null : ledger.expenseCategories}
            />
          }
        />
      </section>
    </div>
  );
}
