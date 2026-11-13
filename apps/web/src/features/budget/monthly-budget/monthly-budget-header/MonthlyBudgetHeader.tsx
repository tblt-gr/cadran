import type { RefObject } from 'react';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { GoalsPanelButton } from '@/features/goals/goal-panel/GoalsPanelButton';
import { MonthlyBudgetActions } from '@/features/budget/monthly-budget/monthly-budget-actions/MonthlyBudgetActions';

/** The month's goals and closure, on the title line; movements are added from the panels. */
export function MonthlyBudgetHeader({
  ready,
  actionsDisabled,
  month,
  goalsButton,
  onManage,
  onClosureChanged,
}: {
  ready: boolean;
  actionsDisabled: boolean;
  month: string;
  goalsButton: RefObject<HTMLButtonElement | null>;
  onManage: () => void;
  onClosureChanged: () => void;
}) {
  return (
    <HeaderActions>
      <GoalsPanelButton
        buttonRef={goalsButton}
        onOpen={onManage}
        ready={ready}
        scope={{ kind: 'month', month }}
      />
      <MonthlyBudgetActions
        disabled={actionsDisabled}
        month={month}
        onClosureChanged={onClosureChanged}
      />
    </HeaderActions>
  );
}
