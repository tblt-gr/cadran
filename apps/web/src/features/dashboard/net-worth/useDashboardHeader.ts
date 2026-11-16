import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import { useNetWorth } from './useNetWorth';

interface DashboardHeader {
  headerDate?: string;
}

/**
 * The business day the dashboard answers for,
 * for the application header.
 *
 * It reads the same cached query as the cards, so the header and the figure
 * below it can never describe two different days.
 */
export function useDashboardHeader(enabled: boolean): DashboardHeader {
  const { i18n } = useTranslation();
  const netWorth = useNetWorth(enabled);

  if (netWorth.data === undefined) {
    return {};
  }

  return {
    headerDate: formatCalendarDay(netWorth.data.asOf, i18n.language),
  };
}
