import type { TransactionDirection } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import styles from './DirectionFilter.module.css';

interface DirectionFilterProps {
  onChange: (value: '' | TransactionDirection) => void;
  value: '' | TransactionDirection;
}

/** Keeps only money coming in, only money going out, or both (the default). */
export function DirectionFilter({ onChange, value }: DirectionFilterProps) {
  const { t } = useTranslation();
  const label = t('transactions.filters.directionLabel');

  return (
    <div className={styles.field}>
      <span className={styles.label}>{label}</span>
      <SegmentedControl<'' | TransactionDirection>
        label={label}
        onChange={onChange}
        options={[
          { label: t('transactions.filters.direction.all'), value: '' },
          { label: t('transactions.filters.direction.INFLOW'), value: 'INFLOW' },
          { label: t('transactions.filters.direction.OUTFLOW'), value: 'OUTFLOW' },
        ]}
        value={value}
      />
    </div>
  );
}
