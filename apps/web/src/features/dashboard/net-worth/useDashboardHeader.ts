import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import { useNetWorth } from './useNetWorth';

interface DashboardHeader {
  freshnessLabel?: string;
  headerDate?: string;
}

/**
 * The business day the dashboard answers for and how fresh its sources are,
 * for the application header.
 *
 * It reads the same cached query as the cards, so the header and the figure
 * below it can never describe two different days.
 */
export function useDashboardHeader(enabled: boolean): DashboardHeader {
  const { i18n, t } = useTranslation();
  const netWorth = useNetWorth(enabled);

  if (netWorth.data === undefined) {
    return {};
  }

  return {
    freshnessLabel:
      netWorth.data.quality === 'MISSING'
        ? t('header.missingFreshness', { count: netWorth.data.missingValuationCount })
        : netWorth.data.quality === 'STALE'
          ? t('header.staleFreshness', { count: netWorth.data.stalestAgeDays ?? 0 })
          : t('header.currentFreshness'),
    headerDate: formatCalendarDay(netWorth.data.asOf, i18n.language),
  };
}
