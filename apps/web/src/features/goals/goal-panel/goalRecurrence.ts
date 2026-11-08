import type { GoalSummary } from '@cadran/api-client';
import type { TFunction } from 'i18next';

type Span = Pick<GoalSummary, 'periodicity' | 'startPeriod' | 'endPeriod'>;

function monthFormat(locale: string, options: Intl.DateTimeFormatOptions, month: string): string {
  return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(
    new Date(`${month}-01T12:00:00Z`),
  );
}

/** "janv." / "juil.": the abbreviated month name of a `YYYY-MM` month. */
export function shortMonth(month: string, locale: string): string {
  return monthFormat(locale, { month: 'short' }, month);
}

/** "nov. 2026". */
function shortMonthYear(month: string, locale: string): string {
  return monthFormat(locale, { month: 'short', year: 'numeric' }, month);
}

function capitalize(text: string, locale: string): string {
  return text.charAt(0).toLocaleUpperCase(locale) + text.slice(1);
}

/** "Novembre 2026": the long month and year of a `YYYY-MM` month, capitalized for a title. */
export function longMonthYear(month: string, locale: string): string {
  return capitalize(monthFormat(locale, { month: 'long', year: 'numeric' }, month), locale);
}

/**
 * How often a goal applies: "Chaque mois depuis nov. 2026", "Novembre 2026 uniquement",
 * "De janv. à juin 2026", "Chaque année depuis 2026", "Année 2026 uniquement", "De 2025 à 2027".
 */
export function goalRecurrence(goal: Span, t: TFunction, locale: string): string {
  const { startPeriod: start, endPeriod: end } = goal;
  if (goal.periodicity === 'YEARLY') {
    if (end === null) return t('goals.panel.recurrence.yearlyOpen', { start });
    if (end === start) return t('goals.panel.recurrence.yearlySingle', { year: start });
    return t('goals.panel.recurrence.range', { start, end });
  }
  if (end === null) {
    return t('goals.panel.recurrence.monthlyOpen', { start: shortMonthYear(start, locale) });
  }
  if (end === start) {
    return t('goals.panel.recurrence.monthlySingle', { month: longMonthYear(start, locale) });
  }
  // Within one year the year is stated once, at the end.
  const sameYear = start.slice(0, 4) === end.slice(0, 4);
  return t('goals.panel.recurrence.range', {
    start: sameYear ? shortMonth(start, locale) : shortMonthYear(start, locale),
    end: shortMonthYear(end, locale),
  });
}
