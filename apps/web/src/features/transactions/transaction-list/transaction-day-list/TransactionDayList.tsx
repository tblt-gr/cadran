import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarWeekdayDay } from '@/lib/decimal';
import type { RowSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { TransactionDayRow } from './TransactionDayRow';
import { groupTransactionsByDay, type DayGroup } from './groupTransactionsByDay';
import styles from './TransactionDayList.module.css';

interface TransactionDayListProps {
  /** Names each row's account. Left out on a list already scoped to one account. */
  accounts?: Account[];
  headingLevel?: 3 | 4;
  /** Accessible name of the list; defaults to the transactions caption. */
  label?: string;
  /** Adds a checkbox per row for bulk editing. */
  selection?: RowSelection;
  /** Pending rows: the loading list mirrors the loaded markup element for element. */
  transactions: Transaction[] | null;
  announce?: boolean;
}

/** Placeholder days: three, three and two rows, enough to fill a screen without inventing data. */
const SKELETON_DAY_SIZES = [3, 3, 2];

export function TransactionDayList({
  accounts,
  announce = true,
  headingLevel = 3,
  label,
  selection,
  transactions,
}: TransactionDayListProps) {
  const { i18n, t } = useTranslation();
  const Heading = `h${headingLevel}` as const;
  const loading = transactions === null;
  const groups: Array<DayGroup | null[]> = loading
    ? SKELETON_DAY_SIZES.map((size) => Array.from({ length: size }, () => null))
    : groupTransactionsByDay(transactions);

  return (
    <div
      className={`card ${styles.panel}`}
      aria-busy={loading || undefined}
      data-testid={loading ? 'transaction-day-list-skeleton' : undefined}
    >
      {loading && announce ? <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus> : null}
      <ol aria-label={label ?? t('transactions.list.caption')} className={styles.days}>
        {groups.map((group, index) => {
          const day = Array.isArray(group) ? null : group;
          const rows: Array<Transaction | null> = Array.isArray(group) ? group : group.transactions;

          return (
            <li className={styles.day} key={day?.day ?? index}>
              <Heading className={styles.dayHeader}>
                <time className={styles.date} dateTime={day?.day}>
                  {day ? formatCalendarWeekdayDay(day.day, i18n.language) : <SkeletonBlock />}
                </time>
              </Heading>
              <ul className={styles.rows}>
                {rows.map((transaction, rowIndex) => (
                  <TransactionDayRow
                    accounts={accounts}
                    key={transaction?.id ?? rowIndex}
                    selection={selection}
                    transaction={transaction}
                  />
                ))}
              </ul>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
