import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './AccountMovementsTable.module.css';

/**
 * AccountMovementsTable while the query is pending: the column headers are
 * real text, only the row values are blocks. `announce` is off when an
 * enclosing page skeleton already owns the loading status.
 */
export function AccountMovementsTableSkeleton({ announce = true }: { announce?: boolean }) {
  const { t } = useTranslation();
  const columns = [
    t('transactions.fields.bookedOn'),
    t('transactions.fields.nature'),
    t('transactions.fields.rawLabel'),
    t('transactions.fields.category'),
    t('accounts.detail.movements.counterpart'),
    t('transactions.fields.amount'),
    t('transactions.fields.state'),
  ];

  return (
    <div
      aria-busy="true"
      className={`card ${styles.panel}`}
      data-testid="account-movements-skeleton"
    >
      {announce ? <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus> : null}
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.detail.movements.caption')}</caption>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column} scope="col">
                  {column}
                </th>
              ))}
            </tr>
          </thead>
          <SkeletonTableRows labels={columns} rows={5} />
        </table>
      </div>
    </div>
  );
}
