import { formatCalendarWeekdayDay } from '@/lib/decimal';
import { todayInBrowser } from '@/lib/businessDay';

/** The calendar day before `day`, as `YYYY-MM-DD`, computed on UTC parts so no timezone shifts it. */
function previousDay(day: string): string {
  const date = new Date(`${day}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() - 1);
  return date.toISOString().slice(0, 10);
}

/** Today and yesterday read as words; every other day keeps its weekday and date. */
export function dayHeadingLabel(
  day: string,
  language: string,
  words: { today: string; yesterday: string },
  today: string = todayInBrowser(),
): string {
  if (day === today) return words.today;
  if (day === previousDay(today)) return words.yesterday;
  return formatCalendarWeekdayDay(day, language);
}
