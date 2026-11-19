import { useEffect, useState } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useTranslation } from 'react-i18next';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import { chartPeriodFrom, type ChartPeriod } from '@/lib/chartPeriodRange';
import { ValueHistoryCardSkeleton } from './ValueHistoryCardSkeleton';
import { ValueHistoryChart } from './ValueHistoryChart';
import { ValueHistoryTable } from './ValueHistoryTable';
import { useValueHistory } from './useValueHistory';
import { HISTORY_PERIODS, historyUrlValue, parseHistoryPeriod } from './valueHistoryRange';
import styles from './ValueHistoryCard.module.css';

function periodFromUrl(): ChartPeriod {
  return parseHistoryPeriod(new URLSearchParams(window.location.search).get('history'));
}

/** Value of the account across its active valuations, ending at the page's Au date. */
export function ValueHistoryCard({ accountId, asOf }: { accountId: string; asOf: string }) {
  const { i18n, t } = useTranslation();
  const [period, setPeriod] = useState<ChartPeriod>(periodFromUrl);
  const [tableOpen, setTableOpen] = useState(false);
  const history = useValueHistory(accountId, asOf, chartPeriodFrom(period, asOf));

  useEffect(() => {
    const sync = () => setPeriod(periodFromUrl());
    window.addEventListener('popstate', sync);
    return () => window.removeEventListener('popstate', sync);
  }, []);

  function changePeriod(next: ChartPeriod) {
    const url = new URL(window.location.href);
    url.searchParams.set('history', historyUrlValue(next));
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    setPeriod(next);
  }

  if (history.isPending) {
    return (
      <ValueHistoryCardSkeleton period={period} status={t('portfolio.account.history.loading')} />
    );
  }

  const data = history.data;
  const valuationsHref = `/portfolios/${accountId}?tab=valuations&asOf=${asOf}`;

  return (
    <section
      aria-busy={history.isPlaceholderData}
      aria-labelledby="portfolio-value-history-title"
      className={`card ${styles.card}`}
    >
      <div className={styles.header}>
        <h3 id="portfolio-value-history-title">{t('portfolio.account.history.title')}</h3>
        <ChartPeriodControl
          label={t('chartPeriod.groupLabel')}
          onChange={changePeriod}
          periods={HISTORY_PERIODS}
          value={period}
        />
      </div>
      {data === undefined ? (
        <div role="alert">
          <p>{t('portfolio.account.history.unavailable')}</p>
          <button className="secondary-action" onClick={() => void history.refetch()} type="button">
            {t('actions.retry')}
          </button>
        </div>
      ) : data.points.length === 0 ? (
        <p role="status">
          {t('portfolio.account.history.empty')}{' '}
          <a
            href={valuationsHref}
            onClick={(event) => handleClientNavigation(event, valuationsHref)}
          >
            {t('portfolio.account.history.valuationsLink')}
          </a>
        </p>
      ) : (
        <>
          {data.points.some((point) => point.totalValue !== null) ? (
            <ValueHistoryChart
              assetCode={data.assetCode}
              locale={i18n.language}
              points={data.points}
            />
          ) : (
            <p role="status">{t('portfolio.account.history.nothingToPlot')}</p>
          )}
          {data.truncated ? (
            <p className={styles.notice} role="status">
              {t('portfolio.account.history.truncated')}
            </p>
          ) : null}
          <Disclosure
            meta={t('portfolio.account.history.count', { count: data.points.length })}
            onToggle={setTableOpen}
            open={tableOpen}
            title={t('portfolio.account.history.viewTable')}
          >
            <ValueHistoryTable locale={i18n.language} points={data.points} />
          </Disclosure>
        </>
      )}
    </section>
  );
}
