import { useTranslation } from 'react-i18next';
import {
  currentMonth,
  type PeriodPreset,
} from '@/features/transactions/transaction-filters/filterState';
import styles from './PeriodFilter.module.css';

const PRESETS: Exclude<PeriodPreset, 'all'>[] = ['month', 'thisYear', 'custom'];

interface PeriodValue {
  from: string;
  month: string;
  period: PeriodPreset;
  to: string;
}

interface PeriodFilterProps extends PeriodValue {
  onChange: (next: PeriodValue) => void;
}

/**
 * The period fieldset of the filter panel: a preset radio group, plus the month picker or the
 * two date fields a preset needs. `onChange` always carries the full value, so a preset switch
 * and an edit inside it go through the same call.
 */
export function PeriodFilter({ from, month, onChange, period, to }: PeriodFilterProps) {
  const { t } = useTranslation();
  const value: PeriodValue = { from, month, period, to };

  return (
    <fieldset className={styles.field}>
      <legend>{t('transactions.filters.periodLabel')}</legend>
      <div className={styles.periodChoices}>
        {PRESETS.map((preset) => (
          <label className={styles.radio} key={preset}>
            <input
              checked={period === preset}
              name="period"
              onChange={() =>
                onChange({
                  ...value,
                  month: preset === 'month' ? month || currentMonth(new Date()) : month,
                  period: preset,
                })
              }
              type="radio"
              value={preset}
            />
            <span>{t(`transactions.filters.period.${preset}`)}</span>
          </label>
        ))}
      </div>
      {period === 'month' ? (
        <label className={styles.customField}>
          <span className="sr-only">{t('transactions.filters.monthLabel')}</span>
          <input
            className={styles.control}
            onChange={(event) => {
              if (event.target.value) {
                onChange({ ...value, month: event.target.value });
              }
            }}
            type="month"
            value={month}
          />
        </label>
      ) : null}
      {period === 'custom' ? (
        <div className={styles.customPeriod}>
          <label className={styles.customField}>
            <span>{t('transactions.filters.from')}</span>
            <input
              className={styles.control}
              onChange={(event) => onChange({ ...value, from: event.target.value })}
              type="date"
              value={from}
            />
          </label>
          <label className={styles.customField}>
            <span>{t('transactions.filters.to')}</span>
            <input
              className={styles.control}
              onChange={(event) => onChange({ ...value, to: event.target.value })}
              type="date"
              value={to}
            />
          </label>
        </div>
      ) : null}
    </fieldset>
  );
}
