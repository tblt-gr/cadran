import { useTranslation } from 'react-i18next';
import { useMultiYearReport } from '@/features/reports/multi-year/useMultiYearReport';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';

/** Month label with year (`Septembre 2026`), capitalised like the Budget month tabs. */
export function formatMonthLabel(month: string, locale: string): string {
  const label = new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));
  return label.charAt(0).toLocaleUpperCase(locale) + label.slice(1);
}

function shiftMonth(month: string, delta: number): string {
  const [year, index] = month.split('-').map(Number);
  const total = year! * 12 + (index! - 1) + delta;
  const nextYear = Math.floor(total / 12);
  return `${nextYear}-${String((total % 12) + 1).padStart(2, '0')}`;
}

interface MonthlyMonthNavigatorProps {
  /** Newest selectable month, `YYYY-MM`. */
  currentMonth: string;
  /** Selected month, `YYYY-MM`. */
  month: string;
  onSelect: (month: string) => void;
}

/**
 * Every available month, newest first, in the same family as the annual year
 * list: horizontal scroll when the range overflows, active month underlined
 * and the current month marked by a dot.
 */
export function MonthlyMonthNavigator({
  currentMonth,
  month,
  onSelect,
}: MonthlyMonthNavigatorProps) {
  const { t, i18n } = useTranslation();
  // Unparameterized: the backend's own default bounds carry `availableFrom`,
  // the workspace's first data year. While they load, the last twelve months
  // are enough for a stable list.
  const bounds = useMultiYearReport();
  const end = month > currentMonth ? month : currentMonth;
  const earliest =
    bounds.data?.availableFrom === null || bounds.data?.availableFrom === undefined
      ? shiftMonth(end, -11)
      : `${bounds.data.availableFrom}-01`;
  const start = month < earliest ? month : earliest;
  const months: string[] = [];
  for (let candidate = end; candidate >= start; candidate = shiftMonth(candidate, -1)) {
    months.push(candidate);
  }

  return (
    <TabsNav
      items={months.map((candidate) => ({
        id: candidate,
        active: candidate === month,
        current: candidate === currentMonth,
        label: formatMonthLabel(candidate, i18n.language),
        onSelect: () => onSelect(candidate),
      }))}
      label={t('reports.month')}
      variant="secondary"
    />
  );
}
