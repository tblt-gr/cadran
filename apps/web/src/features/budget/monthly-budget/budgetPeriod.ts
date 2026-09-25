import type { MonthlyLedger } from '@cadran/api-client';

export type BudgetAxis = Exclude<MonthlyLedger['axis'], null>;
export type ParsedBudgetMonth =
  { kind: 'valid'; month: string } | { kind: 'invalid' } | { kind: 'future' };

// The generator (`@hey-api/openapi-ts`) emits `MonthlyLedger['axis']` as a
// plain TypeScript union, not a runtime value: there is nothing to import an
// array from. This tuple is therefore kept by hand, but `AssertBudgetAxesExhaustive`
// below turns any drift from the generated union into a compile error instead
// of a silently stale list.
const BUDGET_AXES_TUPLE = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
] as const;

export const BUDGET_AXES: readonly BudgetAxis[] = BUDGET_AXES_TUPLE;

type ListedAxis = (typeof BUDGET_AXES_TUPLE)[number];
/** A generated axis this tuple forgot to list. */
type MissingFromBudgetAxes = Exclude<BudgetAxis, ListedAxis>;
/** A listed axis the generated union no longer has. */
type StaleInBudgetAxes = Exclude<ListedAxis, BudgetAxis>;
type AssertBudgetAxesExhaustive = [MissingFromBudgetAxes, StaleInBudgetAxes] extends [never, never]
  ? true
  : ['BUDGET_AXES_TUPLE is out of sync with the generated MonthlyLedger axis union', never];
// Never read; its only purpose is the assignability check above. If the
// generated axis union changes, this line stops compiling.
const _budgetAxesExhaustive: AssertBudgetAxesExhaustive = true;
void _budgetAxesExhaustive;

const MONTH_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

export function parseBudgetMonth(month: string, today: string): ParsedBudgetMonth {
  if (!MONTH_PATTERN.test(month)) return { kind: 'invalid' };
  if (month > today.slice(0, 7)) return { kind: 'future' };

  return { kind: 'valid', month };
}

export function defaultDayForMonth(month: string, today: string): string {
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
