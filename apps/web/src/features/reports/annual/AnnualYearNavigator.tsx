import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';
import { useMultiYearReport } from '@/features/reports/multi-year/useMultiYearReport';

interface AnnualYearNavigatorProps {
  currentYear: number;
  year: number;
}

/**
 * Every available year, newest first, in the same family as the Budget month
 * tabs: horizontal scroll when the range overflows, active year underlined
 * and the current year marked by a dot.
 */
export function AnnualYearNavigator({ currentYear, year }: AnnualYearNavigatorProps) {
  const { t } = useTranslation();
  // Unparameterized: the backend's own default bounds carry `availableFrom`,
  // the workspace's first data year. While they load, the selected year and
  // the current year are enough for a stable list.
  const bounds = useMultiYearReport();
  const minYear = Math.min(bounds.data?.availableFrom ?? year, year, currentYear);
  const years: number[] = [];
  for (let candidate = currentYear; candidate >= minYear; candidate -= 1) {
    years.push(candidate);
  }

  return (
    <TabsNav
      items={years.map((candidate) => ({
        id: String(candidate),
        active: candidate === year,
        current: candidate === currentYear,
        href: `/reports/annual/${candidate}`,
        label: String(candidate),
      }))}
      label={t('reports.annual.yearNavigation')}
      variant="secondary"
    />
  );
}
