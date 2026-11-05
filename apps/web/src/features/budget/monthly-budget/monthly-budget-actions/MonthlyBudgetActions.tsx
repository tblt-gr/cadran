import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { navigateFromPage } from '@/hooks/use-client-navigation';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { PeriodClosurePanel } from '@/features/closures/period-closure-panel/PeriodClosurePanel';

interface MonthlyBudgetActionsProps {
  /** Disabled while the month's ledger is unknown, so the header keeps its shape. */
  disabled: boolean;
  /** `YYYY-MM`: the month the closure panel opens on. */
  month: string;
  onClosureChanged: () => void;
}

/** Overflow menu of the monthly budget header. */
export function MonthlyBudgetActions({
  disabled,
  month,
  onClosureChanged,
}: MonthlyBudgetActionsProps) {
  const { t } = useTranslation();
  const [closureOpen, setClosureOpen] = useState(false);

  return (
    <>
      <ActionMenu
        disabled={disabled}
        items={[
          {
            icon: 'transactions',
            id: 'transactions',
            label: t('budget.monthly.allTransactions'),
            onSelect: () => navigateFromPage(`/transactions?period=month&month=${month}`),
            text: t('budget.monthly.allTransactions'),
          },
          {
            icon: 'categories',
            id: 'categories',
            label: t('budget.monthly.manageCategories'),
            onSelect: () => navigateFromPage('/transactions/categories'),
            text: t('budget.monthly.manageCategories'),
          },
          {
            icon: 'repeat',
            id: 'recurrences',
            label: t('budget.monthly.manageRecurrences'),
            onSelect: () => navigateFromPage('/transactions/recurrences'),
            text: t('budget.monthly.manageRecurrences'),
          },
          {
            icon: 'lock',
            id: 'closure',
            label: t('budget.monthly.closeMonth'),
            onSelect: () => setClosureOpen(true),
            text: t('budget.monthly.closeMonth'),
          },
        ]}
        label={t('budget.monthly.moreActions')}
      />
      {closureOpen ? (
        <PeriodClosurePanel
          close={() => setClosureOpen(false)}
          initialPeriod={month}
          onChanged={onClosureChanged}
        />
      ) : null}
    </>
  );
}
