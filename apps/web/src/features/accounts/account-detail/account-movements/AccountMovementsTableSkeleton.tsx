import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './AccountMovementsTable.module.css';

/**
 * The movements table's final layout while the query is pending: the column
 * headers are real text, only the row values are blocks.
 */
export function AccountMovementsTableSkeleton() {
  const { t } = useTranslation();

  return (
    <div
      aria-busy="true"
      className={`card ${styles.panel}`}
      data-testid="account-movements-skeleton"
    >
      <SkeletonStatus>{t('transactions.loading')}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.detail.movements.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('transactions.fields.bookedOn')}</th>
              <th scope="col">{t('transactions.fields.nature')}</th>
              <th scope="col">{t('transactions.fields.rawLabel')}</th>
              <th scope="col">{t('transactions.fields.category')}</th>
              <th scope="col">{t('accounts.detail.movements.counterpart')}</th>
              <th scope="col">{t('transactions.fields.amount')}</th>
              <th scope="col">{t('transactions.fields.state')}</th>
            </tr>
          </thead>
          <SkeletonTableRows columns={7} rows={5} />
        </table>
      </div>
    </div>
  );
}
