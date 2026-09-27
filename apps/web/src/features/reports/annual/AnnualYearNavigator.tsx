import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useMultiYearReport } from '@/features/reports/multi-year/useMultiYearReport';
import styles from './AnnualYearNavigator.module.css';

interface AnnualYearNavigatorProps {
  currentYear: number;
  year: number;
}

/**
 * Every available year, newest first, in the same family as the Budget month
 * tabs: horizontal scroll when the range overflows, active year in gold and
 * the current year in a distinct colour.
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
    <nav aria-label={t('reports.annual.yearNavigation')} className={styles.years}>
      {years.map((candidate) => {
        const href = `/reports/annual/${candidate}`;
        const active = candidate === year;
        return (
          <a
            aria-current={active ? 'page' : undefined}
            className={
              active ? styles.active : candidate === currentYear ? styles.current : undefined
            }
            href={href}
            key={candidate}
            onClick={(event) => handleClientNavigation(event, href)}
          >
            {candidate}
          </a>
        );
      })}
    </nav>
  );
}
