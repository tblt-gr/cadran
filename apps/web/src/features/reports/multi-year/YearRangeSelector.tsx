import { useTranslation } from 'react-i18next';
import styles from './YearRangeSelector.module.css';

const MAX_SPAN_YEARS = 14;

interface YearRangeSelectorProps {
  /** Inline message shown when the backend refused the current range (422). */
  error?: string | null;
  from: number;
  /** The current workspace-local year: no bound goes beyond it. */
  maxYear: number;
  /** The workspace's first data year: no bound goes before it. */
  minYear: number;
  onChange: (range: { from: number; to: number }) => void;
  to: number;
}

function yearRange(min: number, max: number): number[] {
  const years: number[] = [];
  for (let year = min; year <= max; year += 1) years.push(year);
  return years;
}

/** Two bounded selects: the options themselves never allow `from > to` or a span over 15 years. */
export function YearRangeSelector({
  error,
  from,
  maxYear,
  minYear,
  onChange,
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
    <div className={`card ${styles.selector}`}>
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
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
