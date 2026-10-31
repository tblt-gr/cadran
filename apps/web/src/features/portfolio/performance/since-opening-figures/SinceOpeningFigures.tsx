import type { PortfolioPerformance } from '@cadran/api-client';
import { useState } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import { usePersistedSelection } from '@/hooks/use-persisted-selection';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import {
  figureKeys,
  figureLabels,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import { openPerformanceSource } from '@/features/portfolio/performance/openPerformanceSource';
import { summarizeReason } from '@/features/portfolio/performance/performanceReasons';
import { FigureValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { usePortfolioPerformance } from '@/features/portfolio/performance/usePortfolioPerformance';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { SinceOpeningSettings } from './SinceOpeningSettings';
import styles from './SinceOpeningFigures.module.css';

/** What each figure answers, under its value, so the gain is never read as the latent gain. */
const notes: Record<FigureKey, string> = {
  gain: 'Réalisé et latent, revenus et frais compris',
  modifiedDietz: 'Rendement approché sur la période',
  timeWeighted: 'Hors effet des versements',
  moneyWeighted: 'Rendement de votre argent, par an',
};

const defaultFigures: readonly FigureKey[] = ['gain', 'moneyWeighted'];

function figureNote(key: FigureKey, performance: PortfolioPerformance): string {
  const figure = performance[key];
  const reason = figure.reasons[0];
  if (figure.value === null && reason)
    return summarizeReason(reason, {
      accountId: performance.accountId,
      assetCode: performance.assetCode,
      residual: performance.gain.decomposition.residual,
    });
  return notes[key];
}

/**
 * The since-opening gain and returns, under the summary figures: the reader chooses which ones
 * show (kept in this browser), each opens its explanation. Time-weighted return is the same year
 * figure as the performance tab, so the two screens cannot disagree.
 */
export function SinceOpeningFigures({
  accountId,
  asOf,
  frozen = false,
  standalone = false,
  omit = [],
}: {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  /** Its own card, for a page whose summary has no strip to continue (life insurance). */
  standalone?: boolean;
  /** Figures the page already shows elsewhere, never offered here. */
  omit?: readonly FigureKey[];
}) {
  const [explaining, setExplaining] = useState<FigureKey | null>(null);
  const [choosing, setChoosing] = useState(false);
  const offered = figureKeys.filter((key) => !omit.includes(key));
  const [selected, setShown] = usePersistedSelection(
    'cadran.portfolio.since-opening',
    figureKeys,
    defaultFigures,
  );
  const shown = selected.filter((key) => offered.includes(key));
  const year = asOf.slice(0, 4);
  const inception = usePortfolioPerformance(accountId, 'INCEPTION', undefined, !frozen);
  const yearReturn = usePortfolioPerformance(
    accountId,
    'YEAR',
    year,
    !frozen && shown.includes('timeWeighted'),
  );
  const performance = inception.data ?? null;
  const sourceFor = (key: FigureKey): PortfolioPerformance | null =>
    (key === 'timeWeighted' ? yearReturn.data : performance) ?? null;
  const period =
    performance?.gain.from && performance.gain.to
      ? `Du ${formatCalendarNumericDay(performance.gain.from)} au ${formatCalendarNumericDay(performance.gain.to)}`
      : null;
  const explained = explaining ? sourceFor(explaining) : null;

  return (
    <section
      aria-labelledby="since-opening-title"
      className={standalone ? `card ${styles.standalone}` : styles.section}
    >
      <div className={styles.heading}>
        <h3 id="since-opening-title">Depuis le premier relevé</h3>
        {period ? <span className={styles.period}>{period}</span> : null}
        <button
          aria-label="Choisir les indicateurs depuis le premier relevé"
          className="icon-ghost"
          disabled={frozen}
          onClick={() => setChoosing(true)}
          title="Choisir les indicateurs"
          type="button"
        >
          <Icon name="sliders" />
        </button>
      </div>
      {inception.isError ? (
        <p className={styles.error} role="alert">
          La performance depuis le premier relevé est indisponible.{' '}
          <button className="text-action" onClick={() => void inception.refetch()} type="button">
            Réessayer
          </button>
        </p>
      ) : shown.length > 0 ? (
        <div className={styles.figures}>
          {shown.map((key) => {
            const source = sourceFor(key);
            const label =
              key === 'timeWeighted' ? `${figureLabels[key]} ${year}` : figureLabels[key];
            return (
              <KpiFigure
                explainLabel={`Expliquer : ${label}`}
                key={key}
                label={label}
                note={source ? figureNote(key, source) : undefined}
                onExplain={source ? () => setExplaining(key) : null}
                reason={source?.[key].quality === 'DEGRADED' ? 'Données à vérifier' : undefined}
                size="compact"
                value={
                  !source ? null : source[key].value === null ? (
                    'Non calculable'
                  ) : (
                    <FigureValue assetCode={source.assetCode} figure={source[key]} />
                  )
                }
              />
            );
          })}
        </div>
      ) : null}
      {choosing ? (
        <SinceOpeningSettings
          offered={offered}
          onChange={(next) => setShown([...next, ...selected.filter((key) => omit.includes(key))])}
          onClose={() => setChoosing(false)}
          value={shown}
        />
      ) : null}
      {explaining && explained ? (
        <PerformanceExplanationModal
          figure={explaining}
          onClose={() => setExplaining(null)}
          onSource={(source) => {
            setExplaining(null);
            openPerformanceSource(accountId, asOf, source);
          }}
          performance={explained}
        />
      ) : null}
    </section>
  );
}
