import { useTranslation } from 'react-i18next';
import { useEffect, useRef } from 'react';
import styles from './TransactionListFooter.module.css';

interface TransactionListFooterProps {
  hasMore: boolean;
  failed?: boolean;
  hideEndOfList?: boolean;
  loadingMore: boolean;
  onLoadMore: () => void;
  onReloadFromFirstPage: () => void;
  /** Set once a page request answered `409 transactions.cursor_stale`: the page beyond the
   *  loaded rows was invalidated by a concurrent change and must be reloaded from the start. */
  stale: boolean;
}

/**
 * Paging footer for the transaction list: "load more" while pages remain, an end-of-list
 * notice once `hasMore` is false, and a stale-cursor notice taking priority over both
 * offering to reload from the first page rather than silently discarding the failed request.
 */
export function TransactionListFooter({
  hasMore,
  failed = false,
  hideEndOfList = false,
  loadingMore,
  onLoadMore,
  onReloadFromFirstPage,
  stale,
}: TransactionListFooterProps) {
  const { t } = useTranslation();
  const footer = useRef<HTMLDivElement>(null);
  const wasVisible = useRef(false);
  const latest = useRef({ hasMore, loadingMore, failed, stale, onLoadMore });
  useEffect(() => {
    latest.current = { hasMore, loadingMore, failed, stale, onLoadMore };
  });
  useEffect(() => {
    if (!footer.current || typeof IntersectionObserver === 'undefined') return;
    const observer = new IntersectionObserver(
      ([entry]) => {
        const visible = Boolean(entry?.isIntersecting);
        const approaching = visible && !wasVisible.current;
        wasVisible.current = visible;
        const current = latest.current;
        if (
          approaching &&
          current.hasMore &&
          !current.loadingMore &&
          !current.failed &&
          !current.stale
        ) {
          current.onLoadMore();
        }
      },
      { rootMargin: '0px 0px 240px 0px' },
    );
    observer.observe(footer.current);
    return () => observer.disconnect();
  }, [hasMore, failed, stale]);

  if (stale) {
    return (
      <div className={styles.footer} ref={footer} role="alert">
        <p>{t('transactions.pagination.stale')}</p>
        <button className="secondary-action" onClick={onReloadFromFirstPage} type="button">
          {t('transactions.pagination.reload')}
        </button>
      </div>
    );
  }

  if (!hasMore && hideEndOfList) return null;

  if (!hasMore) {
    return (
      <div className={styles.footer}>
        <p className={styles.endOfList}>{t('transactions.pagination.endOfList')}</p>
      </div>
    );
  }

  return (
    <div className={styles.footer} ref={footer}>
      {failed ? <p role="alert">{t('transactions.pagination.failed')}</p> : null}
      <button
        aria-busy={loadingMore || undefined}
        className="secondary-action"
        disabled={loadingMore}
        onClick={onLoadMore}
        type="button"
      >
        <span aria-live="polite">
          {t(
            loadingMore
              ? 'transactions.pagination.loadingMore'
              : failed
                ? 'foundation.retry'
                : 'transactions.pagination.next',
          )}
        </span>
      </button>
    </div>
  );
}
