import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HoldingsView } from './HoldingsView';
import { InvestmentsHeader } from './InvestmentsHeader';
import { InvestmentsPageSkeleton } from './InvestmentsPageSkeleton';
import {
  DEFAULT_HOLDINGS_SORT,
  parseHoldingsSort,
  useHoldings,
  type HoldingsSort,
} from './useHoldings';
import styles from './InvestmentsPage.module.css';

function readUrlSort(): HoldingsSort {
  return parseHoldingsSort(new URLSearchParams(window.location.search).get('sort'));
}

/** Every holding of the portfolio accounts, grouped by instrument. */
export function InvestmentsPage() {
  const { t } = useTranslation();
  const [sort, setSort] = useState<HoldingsSort>(readUrlSort);
  const holdings = useHoldings(sort);

  useEffect(() => {
    function applyUrlSort() {
      if (window.location.pathname === '/investments') setSort(readUrlSort());
    }
    window.addEventListener('popstate', applyUrlSort);
    return () => window.removeEventListener('popstate', applyUrlSort);
  }, []);

  const changeSort = useCallback((next: HoldingsSort) => {
    const url = new URL(window.location.href);
    if (next === DEFAULT_HOLDINGS_SORT) url.searchParams.delete('sort');
    else url.searchParams.set('sort', next);
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    setSort(next);
  }, []);

  return (
    <div className={styles.page}>
      <InvestmentsHeader path="/investments" />
      {holdings.data === undefined && holdings.isError ? (
        <section className={`card ${styles.empty}`} role="alert">
          <h3>{t('investments.error.title')}</h3>
          <button className="primary-action" onClick={() => void holdings.refetch()} type="button">
            {t('investments.error.retry')}
          </button>
        </section>
      ) : holdings.data === undefined ? (
        <InvestmentsPageSkeleton sort={sort} status={t('investments.loading')} />
      ) : (
        <div className={styles.content}>
          <HoldingsView holdings={holdings.data} onSortChange={changeSort} sort={sort} />
        </div>
      )}
    </div>
  );
}
