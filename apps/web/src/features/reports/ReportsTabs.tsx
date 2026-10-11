import { useTranslation } from 'react-i18next';
import { TabsNav, type TabsNavItem } from '@/components/ui/tabs-nav/TabsNav';
import { useMultiYearReport } from './multi-year/useMultiYearReport';

export type ReportsTab = 'monthly' | 'annual' | 'allYears';

interface ReportsTabsProps {
  active: ReportsTab;
  currentYear: number;
}

export function ReportsTabs({ active, currentYear }: ReportsTabsProps) {
  const { t } = useTranslation();
  // Unparameterized: the backend's own default bounds (first data year to
  // current year) are enough to know whether more than one year exists,
  // without narrowing to any particular range.
  const bounds = useMultiYearReport();
  const singleYear =
    bounds.data?.availableFrom !== null &&
    bounds.data?.availableFrom !== undefined &&
    bounds.data.availableFrom === bounds.data.to;
  const tabs: TabsNavItem[] = [
    {
      id: 'monthly',
      active: active === 'monthly',
      href: '/reports',
      label: t('reports.tabs.monthly'),
    },
    {
      id: 'annual',
      active: active === 'annual',
      href: `/reports/annual/${currentYear}`,
      label: t('reports.tabs.annual'),
    },
    {
      id: 'allYears',
      active: active === 'allYears',
      disabled: singleYear,
      href: '/reports/all-years',
      label: t('reports.tabs.allYears'),
      title: singleYear ? t('reports.tabs.allYearsNeedsHistory') : undefined,
    },
  ];

  return <TabsNav items={tabs} label={t('reports.tabs.label')} />;
}
