import { useTranslation } from 'react-i18next';
import { formatAmount, isZeroDecimal } from '@/lib/decimal';
import styles from './RecurrenceRow.module.css';

/** The amount of a recurrence: red for an expense, green for an income, the sign stays written. */
export function RecurrenceAmount({ assetCode, value }: { assetCode: string; value: string }) {
  const { i18n } = useTranslation();
  const sign = isZeroDecimal(value) ? undefined : value.startsWith('-') ? 'outflow' : 'inflow';

  return (
    <span className={styles.amount} data-sign={sign}>
      {formatAmount(value, assetCode, i18n.language)}
    </span>
  );
}
