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

/** `/budget/YYYY/MM`; the month is a `YYYY-MM` key. */
export function monthHref(month: string, axis: BudgetAxis | null): string {
  const search = axis === null ? '' : `?axis=${axis}`;
  return `/budget/${month.slice(0, 4)}/${month.slice(5)}${search}`;
}

/** The annual report of a year; the multi-year mode and its options live in the query string. */
export function annualHref(year: number | string, search = ''): string {
  return `/budget/${year}${search}`;
}

export type BudgetMonthRoute =
  { kind: 'month'; periodKey: string } | { kind: 'redirect'; href: string };

/**
 * Resolves the `/budget/:year/:month` segments. A month outside 1..12 never reaches the page:
 * a complete year clamps to January or December, the year in progress to January or to the
 * current month. Digits are compared as text, so no number conversion can overflow.
 */
export function resolveBudgetMonthRoute(
  year: string,
  month: string,
  today: string,
): BudgetMonthRoute {
  const negative = month.startsWith('-');
  const digits = (negative ? month.slice(1) : month).replace(/^0+(?=\d)/, '');
  const inRange = !negative && digits.length <= 2 && Number(digits) >= 1 && Number(digits) <= 12;
  const target = (value: string) => ({
    kind: 'redirect' as const,
    href: `/budget/${year}/${value}`,
  });
  if (inRange) {
    const padded = digits.padStart(2, '0');
    return padded === month ? { kind: 'month', periodKey: `${year}-${padded}` } : target(padded);
  }
  if (Number(year) > Number(today.slice(0, 4)))
    return { kind: 'month', periodKey: `${year}-${month}` };
  const belowRange = negative || Number(digits) === 0;
  if (belowRange) return target('01');
  return target(year === today.slice(0, 4) ? today.slice(5, 7) : '12');
}
