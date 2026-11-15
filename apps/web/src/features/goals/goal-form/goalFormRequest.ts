import type {
  CreateGoalRequest,
  GoalScheduleInput,
  GoalScopeType,
  GoalSummary,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { percentageToRatio } from '@/features/goals/goalDecimal';
import { isStockMetric } from '@/features/goals/goalFormFields';
import { toleranceInput } from '@/features/goals/goalTolerance';
import type { GoalFormState } from './goalFormState';

interface GoalRequestContext {
  baseCurrency: string;
  goal: GoalSummary | undefined;
  retroactive: boolean;
  scopeType: GoalScopeType;
  yearlyAmount: boolean;
}

/** The API body of a validated form: an update when a goal is edited, a creation otherwise. */
export function goalRequest(
  state: GoalFormState,
  { baseCurrency, goal, retroactive, scopeType, yearlyAmount }: GoalRequestContext,
): CreateGoalRequest | UpdateGoalRequest {
  const { schedule, valueType, value } = state;
  const label = state.label.trim();
  const target =
    valueType === 'AMOUNT'
      ? { targetAmount: { value, assetCode: goal ? goal.assetCode : baseCurrency } }
      : { ratio: percentageToRatio(value)! };
  const scheduleField: GoalScheduleInput =
    schedule.mode === 'CUSTOM'
      ? { mode: 'CUSTOM', parts: schedule.parts as GoalScheduleInput['parts'] }
      : { mode: 'LINEAR' };
  const tolerance = toleranceInput(state.tolerance, valueType)!;
  const withSchedule = yearlyAmount ? { schedule: scheduleField } : {};

  if (goal) {
    return {
      label,
      version: goal.version,
      valueType,
      effectiveFrom: state.effectiveFrom,
      reason: retroactive ? state.reason.trim() : null,
      ...target,
      tolerance,
      ...withSchedule,
    };
  }

  return {
    label,
    periodicity: state.periodicity,
    metric: state.metric,
    ...(isStockMetric(state.metric) ? { direction: state.direction } : {}),
    scope: { type: scopeType, ref: scopeType === 'WORKSPACE' ? null : state.scope },
    startPeriod: state.start,
    endPeriod: state.noEnd ? null : state.end,
    valueType,
    ...target,
    tolerance,
    ...withSchedule,
  } as CreateGoalRequest;
}
