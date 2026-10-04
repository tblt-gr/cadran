import { useEffect, useState } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useTranslation } from 'react-i18next';
import { PeriodControl } from './PeriodControl';
import { ValueHistoryCardSkeleton } from './ValueHistoryCardSkeleton';
import { ValueHistoryChart } from './ValueHistoryChart';
import { ValueHistoryTable } from './ValueHistoryTable';
import { useValueHistory } from './useValueHistory';
import { historyFrom, parseHistoryPeriod, type HistoryPeriod } from './valueHistoryRange';
import styles from './ValueHistoryCard.module.css';

function periodFromUrl(): HistoryPeriod {
  return parseHistoryPeriod(new URLSearchParams(window.location.search).get('history'));
}

/** Value of the account across its active valuations, ending at the page's Au date. */
export function ValueHistoryCard({ accountId, asOf }: { accountId: string; asOf: string }) {
  const { i18n } = useTranslation();
  const [period, setPeriod] = useState<HistoryPeriod>(periodFromUrl);
  const history = useValueHistory(accountId, asOf, historyFrom(asOf, period));

  useEffect(() => {
    const sync = () => setPeriod(periodFromUrl());
    window.addEventListener('popstate', sync);
    return () => window.removeEventListener('popstate', sync);
  }, []);

  function changePeriod(next: HistoryPeriod) {
    const url = new URL(window.location.href);
    url.searchParams.set('history', next);
    window.history.pushState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    setPeriod(next);
  }

  if (history.isPending) {
    return <ValueHistoryCardSkeleton status="Chargement de l’évolution de la valeur…" />;
  }

  const data = history.data;
  const valuationsHref = `/portfolios/${accountId}?tab=valuations&asOf=${asOf}`;

  return (
    <section aria-labelledby="portfolio-value-history-title" className={`card ${styles.card}`}>
      <div className={styles.header}>
        <h3 id="portfolio-value-history-title">Évolution de la valeur</h3>
        <PeriodControl onChange={changePeriod} value={period} />
      </div>
      {data === undefined ? (
        <div role="alert">
          <p>L’évolution de la valeur est indisponible.</p>
          <button className="secondary-action" onClick={() => void history.refetch()} type="button">
            Réessayer
          </button>
        </div>
      ) : data.points.length === 0 ? (
        <p role="status">
          Aucune valorisation. Enregistrez la valeur de vos titres à partir d’un relevé.{' '}
          <a
            href={valuationsHref}
            onClick={(event) => handleClientNavigation(event, valuationsHref)}
          >
            Valorisations
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
            <p role="status">Aucune valorisation complète à tracer.</p>
          )}
          {data.truncated ? (
            <p className={styles.notice} role="status">
              Seules les 240 dernières valorisations sont affichées.
            </p>
          ) : null}
          <ValueHistoryTable locale={i18n.language} points={data.points} />
        </>
      )}
    </section>
  );
}
