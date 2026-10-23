import { readMonthlyLedgerMovements } from '@cadran/api-client';
import { useInfiniteQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { MovementDateGroup } from './MovementDateGroup';
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

  if (query.isPending)
    return (
      <>
        <SkeletonStatus>{t('budget.monthly.movements.loading')}</SkeletonStatus>
        <ul aria-busy="true" className={styles.movements}>
          {Array.from({ length: 3 }, (_, index) => (
            <MovementDateGroup
              date={null}
              editable={editable}
              kind={kind}
              language={language}
              movements={null}
              onEditTransaction={onEditTransaction}
              key={index}
            />
          ))}
        </ul>
      </>
    );
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

  const groups: Array<{ date: string; items: typeof movements }> = [];
  // Flatten before grouping so a date shared by consecutive pages keeps one heading.
  for (const movement of movements) {
    const last = groups.at(-1);
    if (last?.date === movement.bookedOn) last.items.push(movement);
    else groups.push({ date: movement.bookedOn, items: [movement] });
  }
  return (
    <>
      <ul className={styles.movements}>
        {groups.map((group) => (
          <MovementDateGroup
            date={group.date}
            editable={editable}
            kind={kind}
            language={language}
            movements={group.items}
            onEditTransaction={onEditTransaction}
            key={group.items[0]!.id}
          />
        ))}
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
