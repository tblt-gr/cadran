import type { MonthlyLedger } from '@cadran/api-client';

export type BudgetAxis = Exclude<MonthlyLedger['axis'], null>;
export type ParsedBudgetMonth =
  { kind: 'valid'; month: string } | { kind: 'invalid' } | { kind: 'future' };

export const BUDGET_AXES: readonly BudgetAxis[] = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
];

const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;
/**
 * Used only until the ledger response carries the workspace's own timezone: the
 * route must be validated (past, current or future month) before any request.
 * The session payload does not expose the timezone.
 */
export const FALLBACK_WORKSPACE_TIME_ZONE = 'Europe/Paris';

export function workspaceToday(
  now = new Date(),
  timeZone: string = FALLBACK_WORKSPACE_TIME_ZONE,
): string {
  const parts = new Intl.DateTimeFormat('en', {
    day: '2-digit',
    month: '2-digit',
    timeZone,
    year: 'numeric',
  }).formatToParts(now);
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((candidate) => candidate.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function parseBudgetMonth(month: string, today = workspaceToday()): ParsedBudgetMonth {
  if (!MONTH_PATTERN.test(month)) return { kind: 'invalid' };
  if (month > today.slice(0, 7)) return { kind: 'future' };

  return { kind: 'valid', month };
}

export function defaultDayForMonth(month: string, today = workspaceToday()): string {
  if (month === today.slice(0, 7)) return today;

  const [year, monthNumber] = month.split('-').map((part) => Number.parseInt(part, 10));
  const lastDay = new Date(Date.UTC(year!, monthNumber!, 0)).getUTCDate();

  return `${month}-${String(lastDay).padStart(2, '0')}`;
}

export function readAxis(search: string): BudgetAxis | null {
  const axis = new URLSearchParams(search).get('axis');
  return BUDGET_AXES.find((candidate) => candidate === axis) ?? null;
}

export function monthHref(month: string, axis: BudgetAxis | null): string {
  const search = axis === null ? '' : `?axis=${axis}`;
  return `/budget/${month}${search}`;
}
