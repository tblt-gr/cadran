import type { GoalMetric, GoalScopeType, GoalSummary } from '@cadran/api-client';
import type { GoalScheduleValue } from './goal-schedule-editor/GoalScheduleEditor';

export const annualMetrics: GoalMetric[] = [
  'NET_SAVINGS_TRANSFERS',
  'BUDGET_SURPLUS',
  'CATEGORY_SPENDING',
  'AXIS_SPENDING',
  'ACCOUNT_CONTRIBUTIONS',
];
export const rollingMetrics: GoalMetric[] = [
  'NET_SAVINGS_TRANSFERS',
  'CATEGORY_SPENDING',
  'AXIS_SPENDING',
];

export function scopeFor(metric: GoalMetric): GoalScopeType {
  return metric === 'CATEGORY_SPENDING'
    ? 'CATEGORY'
    : metric === 'AXIS_SPENDING'
      ? 'AXIS'
      : metric === 'ACCOUNT_CONTRIBUTIONS'
        ? 'ACCOUNT'
        : 'WORKSPACE';
}

/** The schedule editor's initial value: the goal's current one when editing, else a blank linear default. */
export function initialSchedule(goal?: GoalSummary): GoalScheduleValue {
  const schedule = goal?.currentVersion.schedule;
  return schedule
    ? { mode: schedule.mode, parts: schedule.parts.map((part) => part.amount) }
    : { mode: 'LINEAR', parts: Array(12).fill('') };
}

export function currentMonthIn(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit' })
    .format(new Date())
    .slice(0, 7);
}
