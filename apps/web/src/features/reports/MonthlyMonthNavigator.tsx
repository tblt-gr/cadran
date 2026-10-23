import { useTranslation } from 'react-i18next';
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
  /** Oldest month with eligible workspace data; null when the workspace has none. */
  firstDataMonth?: string | null;
  month: string;
  onRetry?: () => void;
  onSelect: (month: string) => void;
  /** Bounds query state: the list is disabled until it is `ready`. */
  status?: 'loading' | 'error' | 'ready';
}

/**
 * Every available month, newest first, in the same family as the annual year
 * list: horizontal scroll when the range overflows, active month underlined
 * and the current month marked by a dot.
 */
export function MonthlyMonthNavigator({
  currentMonth,
  firstDataMonth = null,
  month,
  onRetry,
  onSelect,
  status = 'ready',
}: MonthlyMonthNavigatorProps) {
  const { t, i18n } = useTranslation();
  const end = month > currentMonth ? month : currentMonth;
  // While the bounds are unknown the last twelve months keep the list stable; an empty
  // workspace has no history, only the current month.
  const earliest = status !== 'ready' ? shiftMonth(end, -11) : (firstDataMonth ?? currentMonth);
  const start = month < earliest ? month : earliest;
  const months: string[] = [];
  for (let candidate = end; candidate >= start; candidate = shiftMonth(candidate, -1)) {
    months.push(candidate);
  }

  return (
    <>
      <TabsNav
        items={months.map((candidate) => ({
          id: candidate,
          active: candidate === month,
          current: candidate === currentMonth,
          disabled: status !== 'ready',
          label: formatMonthLabel(candidate, i18n.language),
          onSelect: () => onSelect(candidate),
          title: status === 'ready' ? undefined : t('reports.boundsPending'),
        }))}
        label={t('reports.month')}
        variant="secondary"
      />
      {status === 'error' ? (
        <div role="alert">
          <span>{t('reports.boundsError')}</span>{' '}
          <button className="secondary-action" onClick={onRetry} type="button">
            {t('reports.retry')}
          </button>
        </div>
      ) : null}
    </>
  );
}
