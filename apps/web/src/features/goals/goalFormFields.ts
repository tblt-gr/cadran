import type {
  GoalMetric,
  GoalPeriodicity,
  GoalScopeType,
  GoalSummary,
  GoalValueType,
} from '@cadran/api-client';
import type { GoalScheduleValue } from './goal-schedule-editor/GoalScheduleEditor';

export const goalMetrics: GoalMetric[] = [
  'NET_SAVINGS_TRANSFERS',
  'BUDGET_SURPLUS',
  'SAVINGS_RATE',
  'NET_SAVINGS_RATE',
  'CATEGORY_SPENDING',
  'AXIS_SPENDING',
  'ACCOUNT_CONTRIBUTIONS',
  'NET_WORTH',
  'ACCOUNT_BALANCE',
];

export function isStockMetric(metric: GoalMetric): boolean {
  return metric === 'NET_WORTH' || metric === 'ACCOUNT_BALANCE';
}

/** The rate metrics hold a target rate; every other metric holds an amount or a share of income. */
export function isRateMetric(metric: GoalMetric): boolean {
  return metric === 'SAVINGS_RATE' || metric === 'NET_SAVINGS_RATE';
}

/** Mirrors the API matrix: a rate metric takes only `RATE`, a rolling share only a monthly goal. */
export function valueTypesFor(metric: GoalMetric, periodicity: GoalPeriodicity): GoalValueType[] {
  if (isStockMetric(metric)) return ['AMOUNT'];
  if (isRateMetric(metric)) return ['RATE'];
  return periodicity === 'MONTHLY'
    ? ['AMOUNT', 'INCOME_SHARE', 'ROLLING_INCOME_SHARE']
    : ['AMOUNT', 'INCOME_SHARE'];
}

/** First `YYYY-MM` month a goal covers. */
export function firstGoalMonth(periodicity: GoalPeriodicity, startPeriod: string): string {
  return periodicity === 'MONTHLY' ? startPeriod : `${startPeriod}-01`;
}

/** Last `YYYY-MM` month a goal covers, null for a goal without end. */
export function lastGoalMonth(
  periodicity: GoalPeriodicity,
  endPeriod: string | null,
): string | null {
  if (endPeriod === null) return null;
  return periodicity === 'MONTHLY' ? endPeriod : `${endPeriod}-12`;
}

export function scopeFor(metric: GoalMetric): GoalScopeType {
  return metric === 'CATEGORY_SPENDING'
    ? 'CATEGORY'
    : metric === 'AXIS_SPENDING'
      ? 'AXIS'
      : metric === 'ACCOUNT_CONTRIBUTIONS' || metric === 'ACCOUNT_BALANCE'
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

/** Mirrors the API bound on how far a correction may reach back, the current month included. */
export const MAX_CORRECTION_MONTHS = 120;

/** The `YYYY-MM` month `count` months before `month`. */
export function monthsBefore(month: string, count: number): string {
  const index = Number(month.slice(0, 4)) * 12 + (Number(month.slice(5, 7)) - 1) - count;
  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}
