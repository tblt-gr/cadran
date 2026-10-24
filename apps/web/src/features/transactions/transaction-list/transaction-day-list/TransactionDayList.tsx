import type { Account, Transaction } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarWeekdayDay } from '@/lib/decimal';
import { TransactionDayRow } from './TransactionDayRow';
import { TransactionDayTotal } from './TransactionDayTotal';
import { groupTransactionsByDay, type DayGroup } from './groupTransactionsByDay';
import styles from './TransactionDayList.module.css';

interface TransactionDayListProps {
  /** Names each row's account. Left out on a list already scoped to one account. */
  accounts?: Account[];
  /** A search or filter narrows the rows: day totals are then labelled as filtered. */
  filtered?: boolean;
  /** Whether pages remain after the loaded rows: the last day may then be incomplete. */
  hasMore: boolean;
  /** The page was reached by cursor: its first day may have begun on the previous page. */
  hasPrevious?: boolean;
  headingLevel?: 3 | 4;
  /** Accessible name of the list; defaults to the transactions caption. */
  label?: string;
  /** Pending rows: the loading list mirrors the loaded markup element for element. */
  transactions: Transaction[] | null;
  announce?: boolean;
}

/** Placeholder days: three, three and two rows, enough to fill a screen without inventing data. */
const SKELETON_DAY_SIZES = [3, 3, 2];

export function TransactionDayList({
  accounts,
  announce = true,
  filtered = false,
  hasMore,
  hasPrevious = false,
  headingLevel = 3,
  label,
  transactions,
}: TransactionDayListProps) {
  const { i18n, t } = useTranslation();
  const Heading = `h${headingLevel}` as const;
  const loading = transactions === null;
  const groups: Array<DayGroup | null[]> = loading
    ? SKELETON_DAY_SIZES.map((size) => Array.from({ length: size }, () => null))
    : groupTransactionsByDay(transactions, hasMore, hasPrevious);

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
                <TransactionDayTotal filtered={filtered} total={day?.total ?? null} />
              </Heading>
              <ul className={styles.rows}>
                {rows.map((transaction, rowIndex) => (
                  <TransactionDayRow
                    accounts={accounts}
                    key={transaction?.id ?? rowIndex}
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
