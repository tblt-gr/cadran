import type { Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';

interface RefundBadgeProps {
  transaction: Transaction;
}

/** Brings the original row into view when the list holds it; the loaded rows are the only ones that can be reached. */
function showOriginal(id: string | null) {
  if (id === null) return;
  const row = Array.from(document.querySelectorAll<HTMLElement>('[data-transaction-id]')).find(
    (element) => element.dataset.transactionId === id,
  );
  row?.scrollIntoView({ block: 'center' });
  row?.querySelector<HTMLElement>('button')?.focus({ preventScroll: true });
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
        <small>
          <button
            className="text-action"
            onClick={() => showOriginal(transaction.refundOriginalId)}
            type="button"
          >
            {t('transactions.list.refundOf', { label: transaction.refundOriginalLabel })}
          </button>
        </small>
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
