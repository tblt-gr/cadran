import type {
  GoalDirection,
  GoalMetric,
  GoalPeriodicity,
  GoalSummary,
  GoalValueType,
} from '@cadran/api-client';
import type { GoalCreateDefaults } from '@/features/goals/goal-panel/useGoalEditor';
import type { GoalScheduleValue } from '@/features/goals/goal-schedule-editor/GoalScheduleEditor';
import { ratioToPercentage } from '@/features/goals/goalDecimal';
import { initialSchedule, valueTypesFor } from '@/features/goals/goalFormFields';
import { displayTolerance } from '@/features/goals/goalTolerance';

export interface GoalFormState {
  direction: GoalDirection;
  effectiveFrom: string;
  end: string;
  label: string;
  metric: GoalMetric;
  noEnd: boolean;
  periodicity: GoalPeriodicity;
  reason: string;
  schedule: GoalScheduleValue;
  scope: string;
  start: string;
  tolerance: string;
  value: string;
  valueType: GoalValueType;
}

export type GoalFormAction =
  | { type: 'label'; value: string }
  | { type: 'direction'; value: GoalDirection }
  | { type: 'scope'; value: string }
  | { type: 'end'; value: string }
  | { type: 'effectiveFrom'; value: string }
  | { type: 'reason'; value: string }
  | { type: 'value'; value: string }
  | { type: 'tolerance'; value: string }
  | { type: 'schedule'; value: GoalScheduleValue }
  | { type: 'valueType'; value: GoalValueType }
  | { type: 'metric'; value: GoalMetric }
  | { type: 'periodicity'; value: GoalPeriodicity; defaultStart: string }
  | { type: 'start'; value: string }
  | { type: 'noEnd'; value: boolean }
  | { type: 'single'; value: boolean }
  | { type: 'reset'; state: GoalFormState };

export function defaultStartFor(periodicity: GoalPeriodicity, workspaceMonth: string): string {
  return periodicity === 'MONTHLY' ? workspaceMonth : workspaceMonth.slice(0, 4);
}

export function initialGoalFormState(
  goal: GoalSummary | undefined,
  initial: GoalCreateDefaults | undefined,
  workspaceMonth: string,
  defaultEffectiveMonth: string,
): GoalFormState {
  const version = goal?.currentVersion;
  const periodicity = goal?.periodicity ?? initial?.periodicity ?? 'MONTHLY';

  return {
    direction: goal?.direction ?? initial?.direction ?? 'MINIMUM',
    effectiveFrom: defaultEffectiveMonth,
    end: goal?.endPeriod ?? '',
    label: goal?.label ?? initial?.label ?? '',
    metric: goal?.metric ?? initial?.metric ?? 'NET_SAVINGS_TRANSFERS',
    noEnd: goal ? goal.endPeriod === null : true,
    periodicity,
    reason: '',
    schedule: initialSchedule(goal),
    scope: goal?.scope.id ?? goal?.scope.axis ?? initial?.scopeRef ?? '',
    start: goal?.startPeriod ?? initial?.start ?? defaultStartFor(periodicity, workspaceMonth),
    tolerance: version ? displayTolerance(version.tolerance ?? '0', version.valueType) : '',
    value:
      version?.targetAmount ??
      (version?.ratio != null ? (ratioToPercentage(version.ratio) ?? '') : ''),
    valueType: version?.valueType ?? 'AMOUNT',
  };
}

/** An exact amount and a percentage are not interchangeable: never carry one into the other. */
function withValueType(state: GoalFormState, next: GoalValueType): GoalFormState {
  return (state.valueType === 'AMOUNT') !== (next === 'AMOUNT')
    ? { ...state, tolerance: '', value: '', valueType: next }
    : { ...state, valueType: next };
}

function keepValueTypeAllowed(
  state: GoalFormState,
  metric: GoalMetric,
  periodicity: GoalPeriodicity,
): GoalFormState {
  const allowed = valueTypesFor(metric, periodicity);

  return allowed.includes(state.valueType) ? state : withValueType(state, allowed[0]!);
}

export function goalFormReducer(state: GoalFormState, action: GoalFormAction): GoalFormState {
  switch (action.type) {
    case 'label':
    case 'direction':
    case 'scope':
    case 'end':
    case 'effectiveFrom':
    case 'reason':
    case 'value':
    case 'tolerance':
    case 'schedule':
      return { ...state, [action.type]: action.value };
    case 'valueType':
      return withValueType(state, action.value);
    case 'metric':
      return keepValueTypeAllowed(
        { ...state, metric: action.value, scope: '' },
        action.value,
        state.periodicity,
      );
    case 'periodicity':
      return keepValueTypeAllowed(
        {
          ...state,
          end: state.noEnd ? '' : action.defaultStart,
          periodicity: action.value,
          start: action.defaultStart,
        },
        state.metric,
        action.value,
      );
    case 'start': {
      const single = !state.noEnd && state.end !== '' && state.end === state.start;

      return { ...state, end: single ? action.value : state.end, start: action.value };
    }
    case 'noEnd':
      return { ...state, end: action.value ? '' : state.start, noEnd: action.value };
    case 'single':
      return { ...state, end: action.value ? state.start : '', noEnd: !action.value };
    case 'reset':
      return action.state;
  }
}
