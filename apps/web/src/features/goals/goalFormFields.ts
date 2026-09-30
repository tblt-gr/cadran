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
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date());
  const year = parts.find((part) => part.type === 'year')?.value;
  const month = parts.find((part) => part.type === 'month')?.value;
  return `${year}-${month}`;
}

/** Mirrors the API bound on how far a rolling correction may reach back, the current month included. */
export const MAX_CORRECTION_MONTHS = 120;

/** The `YYYY-MM` month `count` months before `month`. */
export function monthsBefore(month: string, count: number): string {
  const index = Number(month.slice(0, 4)) * 12 + (Number(month.slice(5, 7)) - 1) - count;
  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}
