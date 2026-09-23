import {
  readMonthlyLedgerMovements,
  type MonthlyLedgerAccountRow,
  type MonthlyLedgerCategoryRow,
} from '@cadran/api-client';
import { useInfiniteQuery } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { authApiOptions } from '@/features/auth/apiOptions';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import styles from './LedgerRow.module.css';

export type LedgerKind = 'income' | 'expense' | 'account';
export type LedgerRowData = MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow;

interface LedgerRowProps {
  actionsAllowed: boolean;
  addUnavailable?: boolean;
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  onAdd: () => void;
  row: LedgerRowData;
}

export function LedgerRow({
  actionsAllowed,
  addUnavailable = false,
  axis,
  kind,
  language,
  month,
  onAdd,
  row,
}: LedgerRowProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const detailsId = useId();
  const category = kind === 'account' ? null : (row as MonthlyLedgerCategoryRow);
  const amount = renderAmount(row, language, t);
  const addDisabled = !actionsAllowed || addUnavailable;
  const disabledReason = !actionsAllowed
    ? t('budget.monthly.closedReason')
    : t('budget.monthly.accountUnavailable');
  const actionLabel = t(
    kind === 'income'
      ? 'budget.monthly.addIncome'
      : kind === 'expense'
        ? 'budget.monthly.addExpense'
        : 'budget.monthly.addTransfer',
    { label: row.label },
  );

  return (
    <li className={styles.row}>
      <div className={styles.summary}>
        <button
          aria-controls={detailsId}
          aria-expanded={expanded}
          aria-label={t(
            expanded ? 'budget.monthly.hideMovements' : 'budget.monthly.showMovements',
            { label: row.label },
          )}
          className={styles.toggle}
          onClick={() => setExpanded((current) => !current)}
          type="button"
        >
          <span className={expanded ? styles.chevronOpen : styles.chevron}>
            <Icon name="chevron-right" size={18} />
          </span>
        </button>

        <div className={styles.identity}>
          {category ? (
            <CategoryIdentity color={category.color} icon={category.icon} label={category.label} />
          ) : (
            <span className={styles.accountIdentity}>
              <Icon name="accounts" size={18} />
              {row.label}
            </span>
          )}
          <span className={styles.meta}>
            {t('budget.monthly.movementCount', { count: row.movementCount })}
            {category && !category.budgetIncluded ? (
              <span className={styles.marker}>{t('budget.monthly.outOfBudget')}</span>
            ) : null}
            {category?.archived ? (
              <span className={styles.marker}>{t('budget.monthly.archived')}</span>
            ) : null}
          </span>
        </div>

        <span className={styles.amount}>{amount}</span>
        <button
          aria-describedby={addDisabled ? `${detailsId}-closed` : undefined}
          aria-label={actionLabel}
          className={styles.add}
          disabled={addDisabled}
          onClick={onAdd}
          title={addDisabled ? disabledReason : undefined}
          type="button"
        >
          <Icon name="add" size={18} />
        </button>
      </div>

      {addDisabled ? (
        <span className="sr-only" id={`${detailsId}-closed`}>
          {disabledReason}
        </span>
      ) : null}

      {expanded ? (
        <div className={styles.details} id={detailsId}>
          {row.hasMovements ? (
            <MovementDetails
              axis={axis}
              kind={kind}
              language={language}
              month={month}
              rowId={row.id}
            />
          ) : (
            <p>{t('budget.monthly.movements.empty')}</p>
          )}
        </div>
      ) : null}
    </li>
  );
}

function MovementDetails({
  axis,
  kind,
  language,
  month,
  rowId,
}: {
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  rowId: string;
}) {
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
        {movements.map((movement) => (
          <li key={movement.id}>
            <time dateTime={movement.bookedOn}>{formatCalendarNumericDay(movement.bookedOn)}</time>
            <span>
              <strong>
                {movement.direction && movement.counterpartAccountLabel
                  ? t(
                      movement.direction === 'IN'
                        ? 'budget.monthly.movements.in'
                        : 'budget.monthly.movements.out',
                      { account: movement.counterpartAccountLabel },
                    )
                  : movement.label}
              </strong>
            </span>
            <MoneyValue
              value={formatAmount(movement.amount.value, movement.amount.assetCode, language)}
            />
          </li>
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

function renderAmount(row: LedgerRowData, language: string, t: TFunction) {
  if (!row.hasMovements) return t('budget.monthly.noMovement');
  if (row.total.value !== null && row.total.assetCode !== null) {
    return <MoneyValue value={formatAmount(row.total.value, row.total.assetCode, language)} />;
  }
  return t('budget.monthly.mixedAssets');
}
