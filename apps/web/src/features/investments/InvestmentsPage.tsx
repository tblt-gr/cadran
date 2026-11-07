import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HoldingsView } from './HoldingsView';
import { InvestmentsHeader } from './InvestmentsHeader';
import { InvestmentsPageSkeleton } from './InvestmentsPageSkeleton';
import {
  DEFAULT_HOLDINGS_SORT,
  parseHoldingsSort,
  parseHoldingsView,
  useHoldings,
  type HoldingsSort,
  type HoldingsViewMode,
} from './useHoldings';
import styles from './InvestmentsPage.module.css';

function readUrl(): { sort: HoldingsSort; view: HoldingsViewMode } {
  const params = new URLSearchParams(window.location.search);
  return {
    sort: parseHoldingsSort(params.get('sort')),
    view: parseHoldingsView(params.get('view')),
  };
}

/** Every holding of the portfolio accounts, grouped by instrument. */
export function InvestmentsPage() {
  const { t } = useTranslation();
  const [sort, setSort] = useState<HoldingsSort>(() => readUrl().sort);
  const [view, setView] = useState<HoldingsViewMode>(() => readUrl().view);
  const holdings = useHoldings(sort);

  useEffect(() => {
    function applyUrlSort() {
      if (window.location.pathname !== '/investments') return;
      const next = readUrl();
      setSort(next.sort);
      setView(next.view);
    }
    window.addEventListener('popstate', applyUrlSort);
    return () => window.removeEventListener('popstate', applyUrlSort);
  }, []);

  const writeUrl = useCallback((name: string, value: string | null) => {
    const url = new URL(window.location.href);
    if (value === null) url.searchParams.delete(name);
    else url.searchParams.set(name, value);
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
  }, []);
  const changeSort = useCallback(
    (next: HoldingsSort) => {
      writeUrl('sort', next === DEFAULT_HOLDINGS_SORT ? null : next);
      setSort(next);
    },
    [writeUrl],
  );
  const changeView = useCallback(
    (next: HoldingsViewMode) => {
      writeUrl('view', next === 'asset' ? 'asset' : null);
      setView(next);
    },
    [writeUrl],
  );

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
        <InvestmentsPageSkeleton sort={sort} view={view} status={t('investments.loading')} />
      ) : (
        <div className={styles.content}>
          <HoldingsView
            holdings={holdings.data}
            onSortChange={changeSort}
            onViewChange={changeView}
            sort={sort}
            view={view}
          />
        </div>
      )}
    </div>
  );
}
