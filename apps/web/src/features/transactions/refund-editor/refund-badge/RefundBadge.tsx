import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';

interface RefundBadgeProps {
  transaction: Transaction;
}

/** The refund link shown on both rows: the refund names its original, the original shows how much of it has been refunded. */
export function RefundBadge({ transaction }: RefundBadgeProps) {
  const { i18n, t } = useTranslation();

  if (transaction.refundOriginalLabel === null && transaction.refundedAmount === null) {
    return null;
  }

  return (
    <>
      {transaction.refundOriginalLabel !== null ? (
        <small>{t('transactions.list.refundOf', { label: transaction.refundOriginalLabel })}</small>
      ) : null}
      {transaction.refundedAmount !== null ? (
        <small>
          {t('transactions.list.refundedAmount', {
            amount: formatAmount(
              transaction.refundedAmount.value,
              transaction.refundedAmount.assetCode,
              i18n.language,
            ),
          })}
        </small>
      ) : null}
    </>
  );
}
