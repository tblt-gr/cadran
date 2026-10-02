import {
  SkeletonButton,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import footerStyles from './transaction-list/TransactionListFooter.module.css';
import styles from './transaction-list/TransactionList.module.css';

export function TransactionsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <>
      <section
        aria-busy="true"
        className={`card ${styles.panel}`}
        data-testid="transactions-page-skeleton"
      >
        <SkeletonStatus>{status}</SkeletonStatus>
        <div className={styles.tableScroll}>
          <table>
            <thead>
              <tr>
                <th scope="col">{t('transactions.fields.bookedOn')}</th>
                <th scope="col">{t('transactions.fields.rawLabel')}</th>
                <th scope="col">{t('transactions.fields.counterparty')}</th>
                <th scope="col">{t('transactions.fields.category')}</th>
                <th scope="col">{t('transactions.fields.account')}</th>
                <th scope="col">{t('transactions.fields.amount')}</th>
                <th scope="col">{t('transactions.fields.state')}</th>
                <th scope="col">
                  <span className="sr-only">{t('transactions.list.actions')}</span>
                </th>
              </tr>
            </thead>
            <SkeletonTableRows columns={8} rows={8} />
          </table>
        </div>
      </section>
      <div aria-hidden="true" className={footerStyles.footer}>
        <SkeletonButton />
      </div>
    </>
  );
}
