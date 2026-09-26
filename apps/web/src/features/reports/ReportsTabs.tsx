import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './ReportsTabs.module.css';

export type ReportsTab = 'monthly' | 'annual' | 'allYears';

interface ReportsTabsProps {
  active: ReportsTab;
  currentYear: number;
}

export function ReportsTabs({ active, currentYear }: ReportsTabsProps) {
  const { t } = useTranslation();
  const tabs: { id: ReportsTab; href: string; label: string }[] = [
    { id: 'monthly', href: '/reports', label: t('reports.tabs.monthly') },
    { id: 'annual', href: `/reports/annual/${currentYear}`, label: t('reports.tabs.annual') },
    { id: 'allYears', href: '/reports/all-years', label: t('reports.tabs.allYears') },
  ];

  return (
    <nav aria-label={t('reports.tabs.label')} className={styles.tabs}>
      {tabs.map((tab) => (
        <a
          aria-current={tab.id === active ? 'page' : undefined}
          href={tab.href}
          key={tab.id}
          onClick={(event) => handleClientNavigation(event, tab.href)}
        >
          {tab.label}
        </a>
      ))}
    </nav>
  );
}
