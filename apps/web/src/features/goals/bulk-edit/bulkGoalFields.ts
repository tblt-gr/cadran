import type { BulkGoalRequest, GoalSummary, GoalValueType } from '@cadran/api-client';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { percentageToRatio } from '@/features/goals/goalDecimal';
import { toleranceInput } from '@/features/goals/goalTolerance';

export function bulkCompatibility(goals: readonly GoalSummary[]) {
  const first = goals[0];
  const valueType = first?.currentVersion.valueType ?? 'AMOUNT';
  const assetCode = first?.assetCode ?? 'EUR';
  const compatible =
    goals.length > 0 &&
    goals.every(
      (goal) =>
        goal.currentVersion.valueType === valueType &&
        (valueType !== 'AMOUNT' || goal.assetCode === assetCode),
    );
  return {
    compatible,
    valueType,
    assetCode,
    targetAllowed:
      compatible && goals.every((goal) => goal.currentVersion.schedule?.mode !== 'CUSTOM'),
  };
}

/** Conversion of form notation only; the server checks every selected definition's bounds. */
export function bulkChanges(
  changeTarget: boolean,
  target: string,
  changeTolerance: boolean,
  tolerance: string,
  type: GoalValueType,
): BulkGoalRequest['changes'] | null {
  if (!changeTarget && !changeTolerance) return null;
  const changes: BulkGoalRequest['changes'] = {};
  if (changeTarget) {
    const converted =
      type === 'AMOUNT'
        ? isCanonicalUnsignedDecimal(target)
          ? target
          : null
        : percentageToRatio(target);
    if (converted === null) return null;
    changes.target = converted;
  }
  if (changeTolerance) {
    const converted = toleranceInput(tolerance, type);
    if (converted === null) return null;
    changes.tolerance = converted;
  }
  return changes;
}
