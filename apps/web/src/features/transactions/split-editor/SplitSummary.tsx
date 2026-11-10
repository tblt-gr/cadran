import { Icon } from '@/components/ui/icon/Icon';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { formatAmount, isZeroDecimal } from '@/lib/decimal';
import styles from './SplitEditor.module.css';

interface SplitSummaryProps {
  assetCode: string;
  complete: boolean;
  remaining: string | null;
  total: string | null;
}

/** Exact allocation totals and the next step, announced as one status. */
export function SplitSummary({ assetCode, complete, remaining, total }: SplitSummaryProps) {
  const { t, i18n } = useTranslation();
  const id = useId();
  const balanced = remaining !== null && isZeroDecimal(remaining);
  const excess =
    total !== null &&
    remaining !== null &&
    !balanced &&
    total.startsWith('-') !== remaining.startsWith('-');
  return (
    <div className={styles.summary}>
      <div className={styles.remaining}>
        <span>{t('transactions.split.total')}</span>
        <strong>
          {total === null
            ? t('transactions.split.amountUnknown')
            : formatAmount(total, assetCode, i18n.language)}
        </strong>
      </div>
      <div aria-live="polite" className={styles.remaining} role="status">
        <span id={id}>
          {t(
            excess
              ? 'transactions.split.excess'
              : balanced && !complete
                ? 'transactions.split.incomplete'
                : 'transactions.split.remaining',
          )}
        </span>
        <strong aria-labelledby={id}>
          {remaining === null
            ? t('transactions.split.amountUnknown')
            : formatAmount(remaining.replace(/^-/, ''), assetCode, i18n.language)}
        </strong>
        {balanced && complete ? <Icon name="check-circle" size={16} /> : null}
      </div>
    </div>
  );
}
