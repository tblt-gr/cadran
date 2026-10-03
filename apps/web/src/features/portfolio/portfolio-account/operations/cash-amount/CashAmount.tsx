import type { DecimalAmount } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { compareDecimals, formatAmount, isZeroDecimal, negateDecimal } from '@/lib/decimal';
import styles from './CashAmount.module.css';

/** A signed cash effect: the sign is always text, colour only reinforces it. */
export function CashAmount({ amount }: { amount: DecimalAmount }) {
  const { i18n } = useTranslation();
  if (isZeroDecimal(amount.value)) {
    return <span>{formatAmount('0', amount.assetCode, i18n.language)}</span>;
  }
  const negative = compareDecimals(amount.value, '0') < 0;
  const magnitude = negative ? negateDecimal(amount.value) : amount.value;

  return (
    <span className={negative ? styles.negative : styles.positive}>
      {negative ? '−' : '+'}
      {' '}
      {formatAmount(magnitude, amount.assetCode, i18n.language)}
    </span>
  );
}
