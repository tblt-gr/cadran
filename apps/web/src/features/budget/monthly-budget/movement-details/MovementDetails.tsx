import { readMonthlyLedgerMovements } from '@cadran/api-client';
import { useInfiniteQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './MovementDetails.module.css';

interface MovementDetailsProps {
  axis: BudgetAxis | null;
  /** False while the period is closed: rows then read as plain text, not as edit buttons. */
  editable: boolean;
  kind: LedgerKind;
  language: string;
  month: string;
  onEditTransaction?: (transactionId: string) => void;
  rowId: string;
}

export function MovementDetails({
  axis,
  editable,
  kind,
  language,
  month,
  onEditTransaction,
  rowId,
}: MovementDetailsProps) {
  const { t } = useTranslation();
  const query = useInfiniteQuery({
    queryKey: ['monthly-ledger-movements', month, axis, kind, rowId],
    queryFn: async ({ pageParam, signal }) => {
      const result = await readMonthlyLedgerMovements({
        ...authApiOptions(),
        path: { id: rowId, kind },
        query: {
          month,
          ...(kind === 'expense' && axis ? { axis } : {}),
          ...(pageParam ? { cursor: pageParam } : {}),
        },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('monthly-ledger-movements');
      return result.data;
    },
    initialPageParam: null as string | null,
    getNextPageParam: (page) => (page.hasMore ? page.nextCursor : undefined),
    retry: false,
  });

  if (query.isPending) return <p aria-live="polite">{t('budget.monthly.movements.loading')}</p>;
  if (query.isError)
    return (
      <div role="alert">
        <p>{t('budget.monthly.movements.error')}</p>
        <button className="secondary-action" onClick={() => void query.refetch()} type="button">
          {t('budget.monthly.movements.retry')}
        </button>
      </div>
    );

  const movements = query.data.pages.flatMap((page) => page.items);
  if (movements.length === 0) return <p>{t('budget.monthly.movements.empty')}</p>;

  return (
    <>
      <ul className={styles.movements}>
        {movements.map((movement) => {
          const label =
            movement.direction && movement.counterpartAccountLabel
              ? t(
                  movement.direction === 'IN'
                    ? 'budget.monthly.movements.in'
                    : 'budget.monthly.movements.out',
                  { account: movement.counterpartAccountLabel },
                )
              : movement.label;
          const amount = formatAmount(movement.amount.value, movement.amount.assetCode, language);
          const content = (
            <>
              <time dateTime={movement.bookedOn}>
                {formatCalendarNumericDay(movement.bookedOn)}
              </time>
              <strong>{label}</strong>
              <MoneyValue value={amount} />
            </>
          );
          // A transfer leg belongs to a pair: the transaction editor would only be refused.
          const transactionId =
            editable && movement.transferId === null ? movement.transactionId : null;

          return (
            <li key={movement.id}>
              {transactionId !== null && onEditTransaction ? (
                <button
                  aria-label={t('budget.monthly.movements.edit', {
                    amount,
                    date: formatCalendarNumericDay(movement.bookedOn),
                    label,
                  })}
                  className={`${styles.movement} ${styles.movementAction}`}
                  onClick={() => onEditTransaction(transactionId)}
                  type="button"
                >
                  {content}
                </button>
              ) : (
                <div className={styles.movement}>{content}</div>
              )}
            </li>
          );
        })}
      </ul>
      {query.hasNextPage ? (
        <button
          className="secondary-action"
          disabled={query.isFetchingNextPage}
          onClick={() => void query.fetchNextPage()}
          type="button"
        >
          {t(
            query.isFetchingNextPage
              ? 'budget.monthly.movements.loadingMore'
              : 'budget.monthly.movements.more',
          )}
        </button>
      ) : null}
    </>
  );
}
