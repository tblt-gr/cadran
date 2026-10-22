import type { MonthlyLedgerMovement } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import type { LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { formatAmount, formatCalendarDay, formatCalendarDayMonth } from '@/lib/decimal';
import styles from './MovementDetails.module.css';

interface MovementDateGroupProps {
  date: string | null;
  editable: boolean;
  kind: LedgerKind;
  language: string;
  movements: MonthlyLedgerMovement[] | null;
  onEditTransaction?: (id: string) => void;
}

/** One date for every adjacent source movement, shared with the pending layout. */
export function MovementDateGroup({
  date,
  editable,
  kind,
  language,
  movements,
  onEditTransaction,
}: MovementDateGroupProps) {
  const { t } = useTranslation();
  return (
    <li className={styles.dayGroup}>
      <time dateTime={date ?? undefined}>
        {date === null ? (
          <SkeletonBlock className={styles.skeletonDate} />
        ) : (
          formatCalendarDayMonth(date, language)
        )}
      </time>
      <ul className={styles.dayMovements}>
        {(movements ?? [null]).map((movement, index) => {
          const label =
            movement === null
              ? null
              : movement.direction && movement.counterpartAccountLabel
                ? t(
                    movement.direction === 'IN'
                      ? 'budget.monthly.movements.in'
                      : 'budget.monthly.movements.out',
                    { account: movement.counterpartAccountLabel },
                  )
                : movement.label;
          const amount =
            movement === null
              ? null
              : formatAmount(movement.amount.value, movement.amount.assetCode, language);
          const content = (
            <div className={styles.movementLine}>
              <strong title={label ?? undefined}>
                {label ?? <SkeletonBlock className={styles.skeletonLabel} />}
              </strong>
              <MoneyValue skeletonClassName={styles.skeletonAmount} value={amount} />
            </div>
          );
          // The server's category rows are editable transactions; account rows are transfer legs.
          const transactionId = movement?.transferId == null ? movement?.transactionId : null;
          const actionable =
            editable &&
            onEditTransaction &&
            kind !== 'account' &&
            (movement === null || transactionId != null);
          return (
            <li key={movement?.id ?? index}>
              {actionable ? (
                <button
                  aria-label={
                    movement === null
                      ? undefined
                      : t('budget.monthly.movements.edit', {
                          amount,
                          date: formatCalendarDay(movement.bookedOn, language),
                          label,
                        })
                  }
                  className={`${styles.movement} ${styles.movementAction}`}
                  disabled={movement === null}
                  onClick={
                    movement === null
                      ? undefined
                      : () => {
                          if (transactionId != null) onEditTransaction(transactionId);
                        }
                  }
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
    </li>
  );
}
