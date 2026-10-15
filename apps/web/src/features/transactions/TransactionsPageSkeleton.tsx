import { SkeletonBlock, SkeletonButton, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import footerStyles from './transaction-list/TransactionListFooter.module.css';
import styles from './transaction-list/TransactionList.module.css';

/** Mirrors TransactionList and its footer element for element: only the rows are pending. */
export function TransactionsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <>
      <div
        aria-busy="true"
        className={`card ${styles.panel}`}
        data-testid="transactions-page-skeleton"
      >
        <SkeletonStatus>{status}</SkeletonStatus>
        <div className={styles.tableScroll}>
          <table>
            <caption className="sr-only">{t('transactions.list.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('transactions.fields.bookedOn')}</th>
                <th scope="col">{t('transactions.fields.rawLabel')}</th>
                <th scope="col">{t('transactions.fields.category')}</th>
                <th scope="col">{t('transactions.fields.account')}</th>
                <th className={styles.amount} scope="col">
                  {t('transactions.fields.amount')}
                </th>
                <th scope="col">{t('transactions.fields.state')}</th>
                <th scope="col">
                  <span className="sr-only">{t('transactions.list.actions')}</span>
                </th>
              </tr>
            </thead>
            <SkeletonRows />
          </table>
        </div>
      </div>
      <div aria-hidden="true" className={footerStyles.footer}>
        <SkeletonButton />
      </div>
    </>
  );
}

/** Rows built on the list's own cells, so the phone card layout holds while loading. */
function SkeletonRows() {
  const cells = [styles.date, styles.label, styles.category, styles.account, styles.amount];

  return (
    <tbody aria-hidden="true">
      {Array.from({ length: 8 }, (_, row) => (
        <tr key={row}>
          {cells.map((cell) => (
            <td className={cell} key={cell}>
              <SkeletonBlock className={styles.skeletonValue} />
            </td>
          ))}
          {/* Most rows are booked and carry no state badge: the loading row leaves it out too. */}
          <td className={styles.state} />
          <td className={styles.actions}>
            <SkeletonBlock className={styles.skeletonValue} />
          </td>
        </tr>
      ))}
    </tbody>
  );
}
