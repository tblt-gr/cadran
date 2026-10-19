import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import type { NetWorthPeriod } from './useNetWorthPeriod';
import styles from './NetWorthPeriodControl.module.css';

interface Props {
  period: NetWorthPeriod;
  onChange?: (period: NetWorthPeriod) => void;
  disabled?: boolean;
}

export function NetWorthPeriodControl({ period, onChange, disabled = false }: Props) {
  const { t } = useTranslation();
  const name = useId();
  const options: NetWorthPeriod[] = ['ALL', 'LAST_12_MONTHS', 'YTD'];
  return (
    <div role="radiogroup" aria-label={t('dashboard.chart.periodLabel')} className={styles.track}>
      {options.map((value) => (
        <label className={styles.segment} key={value}>
          <input
            className="sr-only"
            type="radio"
            name={name}
            value={value}
            checked={period === value}
            disabled={disabled}
            onChange={() => onChange?.(value)}
          />
          <span>{t(`dashboard.chart.periods.${value}`)}</span>
        </label>
      ))}
    </div>
  );
}
