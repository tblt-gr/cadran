import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useMultiYearReport } from './multi-year/useMultiYearReport';
import styles from './ReportsTabs.module.css';

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
  const tabs: { id: ReportsTab; href: string; label: string }[] = [
    { id: 'monthly', href: '/reports', label: t('reports.tabs.monthly') },
    { id: 'annual', href: `/reports/annual/${currentYear}`, label: t('reports.tabs.annual') },
    { id: 'allYears', href: '/reports/all-years', label: t('reports.tabs.allYears') },
  ];

  return (
    <nav aria-label={t('reports.tabs.label')} className={styles.tabs}>
      {tabs.map((tab) => {
        const disabled = tab.id === 'allYears' && singleYear;
        if (disabled) {
          return (
            <span aria-disabled="true" key={tab.id} title={t('reports.tabs.allYearsNeedsHistory')}>
              {tab.label}
            </span>
          );
        }

        return (
          <a
            aria-current={tab.id === active ? 'page' : undefined}
            href={tab.href}
            key={tab.id}
            onClick={(event) => handleClientNavigation(event, tab.href)}
          >
            {tab.label}
          </a>
        );
      })}
    </nav>
  );
}
