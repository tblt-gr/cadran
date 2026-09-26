import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AnnualReportToolbar.module.css';

interface AnnualReportToolbarProps {
  currentYear: number;
  includeIncomplete: boolean;
  onOpenColumns: () => void;
  onToggleIncomplete: () => void;
  previousYearHasData: boolean;
  saving: boolean;
  year: number;
}

export function AnnualReportToolbar({
  currentYear,
  includeIncomplete,
  onOpenColumns,
  onToggleIncomplete,
  previousYearHasData,
  saving,
  year,
}: AnnualReportToolbarProps) {
  const { t } = useTranslation();
  const previous = `/reports/annual/${year - 1}`;
  const next = `/reports/annual/${year + 1}`;

  return (
    <div className={styles.toolbar}>
      <div className={styles.years}>
        {previousYearHasData ? (
          <a href={previous} onClick={(event) => handleClientNavigation(event, previous)}>
            {t('reports.annual.previousYear')}
          </a>
        ) : (
          <span aria-describedby="annual-previous-year-hint" aria-disabled="true">
            {t('reports.annual.previousYear')}
            <span className="sr-only" id="annual-previous-year-hint">
              {t('reports.annual.previousYearEmpty')}
            </span>
          </span>
        )}
        <strong>{year}</strong>
        {year >= currentYear ? (
          <span aria-disabled="true">{t('reports.annual.nextYear')}</span>
        ) : (
          <a href={next} onClick={(event) => handleClientNavigation(event, next)}>
            {t('reports.annual.nextYear')}
          </a>
        )}
      </div>
      <button
        aria-checked={includeIncomplete}
        aria-label={t('reports.annual.incompleteLabel')}
        className="secondary-action"
        disabled={saving}
        onClick={onToggleIncomplete}
        role="switch"
        type="button"
      >
        {t('reports.annual.incompleteMonths', {
          state: t(includeIncomplete ? 'reports.annual.included' : 'reports.annual.excluded'),
        })}
      </button>
      <button className="secondary-action" onClick={onOpenColumns} type="button">
        {t('reports.annual.columns')}
      </button>
    </div>
  );
}
