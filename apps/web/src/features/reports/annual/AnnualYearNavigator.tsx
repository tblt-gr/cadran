import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AnnualYearNavigator.module.css';

interface AnnualYearNavigatorProps {
  currentYear: number;
  previousYearHasData: boolean;
  year: number;
}

/** Round previous/next controls around the year, in the same family as the Budget month navigator. */
export function AnnualYearNavigator({
  currentYear,
  previousYearHasData,
  year,
}: AnnualYearNavigatorProps) {
  const { t } = useTranslation();
  const previous = `/reports/annual/${year - 1}`;
  const next = `/reports/annual/${year + 1}`;

  return (
    <nav aria-label={t('reports.annual.yearNavigation')} className={styles.navigator}>
      {previousYearHasData ? (
        <a
          aria-label={t('reports.annual.previousYear')}
          className={`icon-button ${styles.step}`}
          href={previous}
          onClick={(event) => handleClientNavigation(event, previous)}
          title={t('reports.annual.previousYear')}
        >
          <Icon name="chevron-left" size={16} />
        </a>
      ) : (
        <span
          aria-describedby="annual-previous-year-hint"
          aria-disabled="true"
          className={`icon-button ${styles.step}`}
          title={t('reports.annual.previousYearEmpty')}
        >
          <Icon name="chevron-left" size={16} />
          <span className="sr-only">{t('reports.annual.previousYear')}</span>
          <span className="sr-only" id="annual-previous-year-hint">
            {t('reports.annual.previousYearEmpty')}
          </span>
        </span>
      )}
      <strong aria-current="page" className={styles.year}>
        {year}
      </strong>
      {year >= currentYear ? (
        <span aria-disabled="true" className={`icon-button ${styles.step}`}>
          <Icon name="chevron-right" size={16} />
          <span className="sr-only">{t('reports.annual.nextYear')}</span>
        </span>
      ) : (
        <a
          aria-label={t('reports.annual.nextYear')}
          className={`icon-button ${styles.step}`}
          href={next}
          onClick={(event) => handleClientNavigation(event, next)}
          title={t('reports.annual.nextYear')}
        >
          <Icon name="chevron-right" size={16} />
        </a>
      )}
    </nav>
  );
}
