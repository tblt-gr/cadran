import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
