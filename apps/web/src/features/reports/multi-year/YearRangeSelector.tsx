import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './YearRangeSelector.module.css';

const MAX_SPAN_YEARS = 14;

export type MultiYearDisplayMode = 'compact' | 'detailed';

interface YearRangeSelectorProps {
  /** Omitted while the range itself is invalid: there is nothing to switch the display of yet. */
  display?: MultiYearDisplayMode;
  /** Inline message shown when the backend refused the current range (422). */
  error?: string | null;
  from: number;
  /** The current workspace-local year: no bound goes beyond it. */
  maxYear: number;
  /** The workspace's first data year: no bound goes before it. */
  minYear: number;
  onChange: (range: { from: number; to: number }) => void;
  onDisplayChange?: (display: MultiYearDisplayMode) => void;
  to: number;
}

function yearRange(min: number, max: number): number[] {
  const years: number[] = [];
  for (let year = min; year <= max; year += 1) years.push(year);
  return years;
}

/** Two bounded selects: the options themselves never allow `from > to` or a span over 15 years. */
export function YearRangeSelector({
  display,
  error,
  from,
  maxYear,
  minYear,
  onChange,
  onDisplayChange,
  to,
}: YearRangeSelectorProps) {
  const { t } = useTranslation();
  const fromOptions = yearRange(minYear, maxYear);
  const toOptions = yearRange(from, Math.min(maxYear, from + MAX_SPAN_YEARS));

  function changeFrom(nextFrom: number) {
    const bound = Math.min(maxYear, nextFrom + MAX_SPAN_YEARS);
    onChange({ from: nextFrom, to: Math.min(Math.max(to, nextFrom), bound) });
  }
  function changeTo(nextTo: number) {
    onChange({ from: Math.max(from, nextTo - MAX_SPAN_YEARS, minYear), to: nextTo });
  }

  return (
    <div className={styles.selector}>
      <div className={styles.row}>
        <div className={styles.fields}>
          <div className={styles.field}>
            <label htmlFor="multi-year-range-from">{t('reports.multiYear.from')}</label>
            <select
              id="multi-year-range-from"
              onChange={(event) => changeFrom(Number(event.target.value))}
              value={from}
            >
              {fromOptions.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>
          </div>
          <div className={styles.field}>
            <label htmlFor="multi-year-range-to">{t('reports.multiYear.to')}</label>
            <select
              id="multi-year-range-to"
              onChange={(event) => changeTo(Number(event.target.value))}
              value={to}
            >
              {toOptions.map((year) => (
                <option key={year} value={year}>
                  {year}
                </option>
              ))}
            </select>
          </div>
        </div>
        {display && onDisplayChange ? (
          <div
            aria-label={t('reports.multiYear.displayMode')}
            className={styles.displayModes}
            role="group"
          >
            <button
              aria-label={t('reports.multiYear.compact')}
              aria-pressed={display === 'compact'}
              disabled={display === 'compact'}
              onClick={() => onDisplayChange('compact')}
              title={t('reports.multiYear.compact')}
              type="button"
            >
              <Icon name="menu" size={16} />
            </button>
            <button
              aria-label={t('reports.multiYear.detailed')}
              aria-pressed={display === 'detailed'}
              disabled={display === 'detailed'}
              onClick={() => onDisplayChange('detailed')}
              title={t('reports.multiYear.detailed')}
              type="button"
            >
              <Icon name="catalog" size={16} />
            </button>
          </div>
        ) : null}
      </div>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
