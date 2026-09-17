import { useTranslation } from 'react-i18next';
import type { PeriodPreset } from '@/features/transactions/transaction-filters/filterState';
import styles from './PeriodFilter.module.css';

const PRESETS: Exclude<PeriodPreset, 'all'>[] = ['thisMonth', 'lastMonth', 'thisYear', 'custom'];

interface PeriodFilterProps {
  from: string;
  onChange: (period: PeriodPreset, from: string, to: string) => void;
  period: PeriodPreset;
  to: string;
}

/**
 * The period fieldset of the filter bar: a preset radio group, plus the two date fields a
 * custom range needs. `onChange` always carries the full triple, so a preset switch away from
 * `custom` and a date edit inside it go through the same call.
 */
export function PeriodFilter({ from, onChange, period, to }: PeriodFilterProps) {
  const { t } = useTranslation();

  return (
    <fieldset className={styles.field}>
      <legend>{t('transactions.filters.periodLabel')}</legend>
      <div className={styles.periodChoices}>
        {PRESETS.map((preset) => (
          <label className={styles.radio} key={preset}>
            <input
              checked={period === preset}
              name="period"
              onChange={() => onChange(preset, from, to)}
              type="radio"
              value={preset}
            />
            <span>{t(`transactions.filters.period.${preset}`)}</span>
          </label>
        ))}
      </div>
      {period === 'custom' ? (
        <div className={styles.customPeriod}>
          <label>
            <span>{t('transactions.filters.from')}</span>
            <input
              className={styles.control}
              onChange={(event) => onChange(period, event.target.value, to)}
              type="date"
              value={from}
            />
          </label>
          <label>
            <span>{t('transactions.filters.to')}</span>
            <input
              className={styles.control}
              onChange={(event) => onChange(period, from, event.target.value)}
              type="date"
              value={to}
            />
          </label>
        </div>
      ) : null}
    </fieldset>
  );
}
