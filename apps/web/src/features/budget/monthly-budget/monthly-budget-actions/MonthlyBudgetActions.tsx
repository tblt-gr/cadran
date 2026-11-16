import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { PeriodClosurePanel } from '@/features/closures/period-closure-panel/PeriodClosurePanel';

interface MonthlyBudgetActionsProps {
  /** Disabled while the month's ledger is unknown, so the header keeps its shape. */
  disabled: boolean;
  /** `YYYY-MM`: the month the closure panel opens on. */
  month: string;
  onClosureChanged: () => void;
}

/** The month's closure, the one budget action the tabs do not already reach. */
export function MonthlyBudgetActions({
  disabled,
  month,
  onClosureChanged,
}: MonthlyBudgetActionsProps) {
  const { t } = useTranslation();
  const [closureOpen, setClosureOpen] = useState(false);
  const button = useRef<HTMLButtonElement>(null);

  return (
    <>
      <button
        ref={button}
        aria-label={t('budget.monthly.closeMonth')}
        className="icon-button"
        disabled={disabled}
        onClick={() => setClosureOpen(true)}
        title={t('budget.monthly.closeMonth')}
        type="button"
      >
        <Icon name="lock" size={18} />
      </button>
      {closureOpen ? (
        <PeriodClosurePanel
          close={() => setClosureOpen(false)}
          initialPeriod={month}
          onChanged={onClosureChanged}
          returnFocus={button}
        />
      ) : null}
    </>
  );
}
