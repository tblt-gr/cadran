import { useTranslation } from 'react-i18next';
import styles from './TransactionListFooter.module.css';

interface TransactionListFooterProps {
  hasMore: boolean;
  loadingMore: boolean;
  onLoadMore: () => void;
  onReloadFromFirstPage: () => void;
  /** Set once a page request answered `409 transactions.cursor_stale`: the page beyond the
   *  loaded rows was invalidated by a concurrent change and must be reloaded from the start. */
  stale: boolean;
}

/**
 * Paging footer for the transaction list: "load more" while pages remain, an end-of-list
 * notice once `hasMore` is false, and — taking priority over both — a stale-cursor notice
 * offering to reload from the first page rather than silently discarding the failed request.
 */
export function TransactionListFooter({
  hasMore,
  loadingMore,
  onLoadMore,
  onReloadFromFirstPage,
  stale,
}: TransactionListFooterProps) {
  const { t } = useTranslation();

  if (stale) {
    return (
      <div className={styles.footer} role="alert">
        <p>{t('transactions.pagination.stale')}</p>
        <button className="secondary-action" onClick={onReloadFromFirstPage} type="button">
          {t('transactions.pagination.reload')}
        </button>
      </div>
    );
  }

  if (!hasMore) {
    return (
      <div className={styles.footer}>
        <p className={styles.endOfList}>{t('transactions.pagination.endOfList')}</p>
      </div>
    );
  }

  return (
    <div className={styles.footer}>
      <button
        aria-busy={loadingMore || undefined}
        className="secondary-action"
        disabled={loadingMore}
        onClick={onLoadMore}
        type="button"
      >
        {t(loadingMore ? 'transactions.pagination.loadingMore' : 'transactions.pagination.next')}
      </button>
    </div>
  );
}
