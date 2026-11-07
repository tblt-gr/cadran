import type { GoalSummary } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatGoalMonth } from './goalMonth';

/** Renders a goal period: a `YYYY-MM` month in words, a `YYYY` year as is. */
function formatPeriod(periodicity: GoalSummary['periodicity'], period: string, locale: string) {
  return periodicity === 'MONTHLY' ? formatGoalMonth(period, locale) : period;
}

/** "Mensuel, depuis novembre 2026", "Annuel, 2026", "Mensuel, de janvier 2026 à mars 2026". */
export function goalPeriodLabel(
  goal: Pick<GoalSummary, 'periodicity' | 'startPeriod' | 'endPeriod'>,
  t: TFunction,
  locale: string,
): string {
  const start = formatPeriod(goal.periodicity, goal.startPeriod, locale);
  const periodicity = t(`goals.periodicities.${goal.periodicity}`);
  if (goal.endPeriod === null) return t('goals.period.open', { periodicity, start });
  if (goal.endPeriod === goal.startPeriod) return t('goals.period.single', { periodicity, start });
  const end = formatPeriod(goal.periodicity, goal.endPeriod, locale);
  return t('goals.period.range', { periodicity, start, end });
}
