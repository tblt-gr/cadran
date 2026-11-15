import type { GoalSummary } from '@cadran/api-client';
import { useReducer, useState } from 'react';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import type { GoalCreateDefaults } from '@/features/goals/goal-panel/useGoalEditor';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import {
  isValidPositiveRatioPercentage,
  isValidRatePercentage,
} from '@/features/goals/goalDecimal';
import {
  currentMonthIn,
  firstGoalMonth,
  isStockMetric,
  lastGoalMonth,
  MAX_CORRECTION_MONTHS,
  monthsBefore,
  scopeFor,
} from '@/features/goals/goalFormFields';
import { validTolerance } from '@/features/goals/goalTolerance';
import {
  defaultStartFor,
  goalFormReducer,
  initialGoalFormState,
  type GoalFormState,
} from './goalFormState';

const MONTH_FORMAT = /^\d{4}-(0[1-9]|1[0-2])$/;

/**
 * Owns the typed values of the goal form, the derived validity of each group and the
 * transitions between fields (changing the metric, the periodicity or the value type).
 */
export function useGoalFormState(goal: GoalSummary | undefined, initial?: GoalCreateDefaults) {
  const timeZone = useWorkspaceTimeZone();
  const workspaceMonth = currentMonthIn(timeZone);
  const currentYear = Number(workspaceMonth.slice(0, 4));
  const earliestEffectiveMonth = goal
    ? firstGoalMonth(goal.periodicity, goal.startPeriod)
    : workspaceMonth;
  const lastMonth = goal ? lastGoalMonth(goal.periodicity, goal.endPeriod) : null;
  const latestEffectiveMonth =
    lastMonth !== null && lastMonth < workspaceMonth ? lastMonth : workspaceMonth;
  const defaultEffectiveMonth =
    workspaceMonth >= earliestEffectiveMonth && workspaceMonth <= latestEffectiveMonth
      ? workspaceMonth
      : latestEffectiveMonth;
  const [state, dispatch] = useReducer(goalFormReducer, undefined, () =>
    initialGoalFormState(goal, initial, workspaceMonth, defaultEffectiveMonth),
  );
  const [showErrors, setShowErrors] = useState(false);
  const { effectiveFrom, end, label, metric, noEnd, periodicity, reason, start, value, valueType } =
    state;

  const monthly = periodicity === 'MONTHLY';
  const amount = valueType === 'AMOUNT';
  const single = !noEnd && end !== '' && end === start;
  // A correction reaches back at most this many months, the current one included.
  const oldestCorrectionMonth = monthsBefore(workspaceMonth, MAX_CORRECTION_MONTHS - 1);
  const minEffectiveMonth =
    oldestCorrectionMonth > earliestEffectiveMonth ? oldestCorrectionMonth : earliestEffectiveMonth;
  const futureGoal = Boolean(goal && earliestEffectiveMonth > workspaceMonth);
  const scopeType = scopeFor(metric);
  const labelValid = label.trim().length >= 1 && [...label.trim()].length <= 80;
  const amountValid =
    isCanonicalUnsignedDecimal(value) &&
    !/^0(?:\.0+)?$/.test(value) &&
    /^\d+(?:\.\d{1,8})?$/.test(value);
  const decimalValid = amount
    ? amountValid
    : valueType === 'RATE'
      ? isValidRatePercentage(value)
      : isValidPositiveRatioPercentage(value);
  const toleranceValid = validTolerance(state.tolerance, value, valueType);
  const scopeValid = scopeType === 'WORKSPACE' || state.scope !== '';
  const periodFormat = (period: string) =>
    monthly
      ? MONTH_FORMAT.test(period)
      : /^\d{4}$/.test(period) && Number(period) >= 1900 && Number(period) <= currentYear + 5;
  const startValid = periodFormat(start);
  const endValid = noEnd || (periodFormat(end) && end >= start);
  const yearlyAmount = periodicity === 'YEARLY' && amount && !isStockMetric(metric);
  const scheduleValid =
    !yearlyAmount ||
    state.schedule.mode === 'LINEAR' ||
    state.schedule.parts.every((part) => isCanonicalUnsignedDecimal(part));
  const effectiveMonthValid =
    !goal ||
    (MONTH_FORMAT.test(effectiveFrom) &&
      effectiveFrom >= minEffectiveMonth &&
      effectiveFrom <= latestEffectiveMonth);
  const retroactive = Boolean(goal && effectiveFrom < workspaceMonth);
  const reasonValid = !retroactive || (reason.trim().length >= 3 && reason.trim().length <= 200);
  const formValid =
    labelValid &&
    decimalValid &&
    toleranceValid &&
    scopeValid &&
    startValid &&
    endValid &&
    scheduleValid &&
    effectiveMonthValid &&
    reasonValid;

  return {
    amount,
    decimalValid,
    dispatch,
    endValid,
    effectiveMonthValid,
    formValid,
    futureGoal,
    labelValid,
    latestEffectiveMonth,
    minEffectiveMonth,
    reasonValid,
    reset: (next: GoalSummary | undefined) =>
      dispatch({
        type: 'reset',
        state: initialGoalFormState(next, initial, workspaceMonth, defaultEffectiveMonth),
      }),
    retroactive,
    scopeType,
    scopeValid,
    setShowErrors,
    showErrors,
    single,
    startValid,
    state: state as GoalFormState,
    defaultStart: (next: GoalSummary['periodicity']) => defaultStartFor(next, workspaceMonth),
    toleranceValid,
    yearlyAmount,
  };
}
