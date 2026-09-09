import type { DecimalAmount } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import styles from './TransactionAmount.module.css';

interface TransactionAmountProps {
  amount: DecimalAmount;
}

export function TransactionAmount({ amount }: TransactionAmountProps) {
  const { i18n, t } = useTranslation();
  const negative = amount.value.startsWith('-');
  const figure = formatAmount(amount.value, amount.assetCode, i18n.language);
  const spoken = formatAmount(amount.value.replace(/^-/, ''), amount.assetCode, i18n.language);
  const signed = negative || figure.startsWith('+') ? figure : `+${figure}`;

  return (
    <span
      aria-label={t(negative ? 'transactions.amount.outflow' : 'transactions.amount.inflow', {
        amount: spoken,
      })}
      className={styles.amount}
      data-sign={negative ? 'outflow' : 'inflow'}
    >
      <MoneyValue value={signed} />
    </span>
  );
}
