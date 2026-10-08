import { figureLabels, type ContractFigure } from './contractFigureLabels';
import { useState, type RefObject } from 'react';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { QualityBadge } from '@/features/portfolio/performance/quality-badge/QualityBadge';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import { ContractFigureExplanation } from './ContractFigureExplanation';
import {
  money,
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './ContractFigures.module.css';
export function ContractFigures({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const [explaining, setExplaining] = useState<ContractFigure | null>(null);
  const values = summary
    ? {
        value: summary.valuation?.totalValue ?? null,
        premiums: summary.cumulative.premiums,
        surrenders: summary.cumulative.surrendersNetPaid,
        gain: summary.gainSinceOpening.value,
        fees: summary.cumulative.fees.total,
      }
    : null;
  return (
    <section
      className={`card ${styles.figures}`}
      aria-label="Indicateurs du contrat"
      aria-busy={!summary}
    >
      {(Object.keys(figureLabels) as ContractFigure[]).map((key) => (
        <KpiFigure
          key={key}
          label={figureLabels[key]}
          explainLabel={`Expliquer : ${figureLabels[key]}`}
          size={key === 'value' ? 'display' : 'compact'}
          value={
            values
              ? key === 'value' && !summary?.valuation
                ? '-'
                : money(values[key], summary!.assetCode)
              : null
          }
          note={
            key === 'value' && summary?.valuation
              ? `Valorisation du ${formatCalendarNumericDay(summary.valuation.valuedOn)}`
              : undefined
          }
          onExplain={
            summary
              ? () => {
                  if (sourceReturnFocus)
                    sourceReturnFocus.current =
                      document.activeElement instanceof HTMLElement ? document.activeElement : null;
                  setExplaining(key);
                }
              : null
          }
        />
      ))}
      {summary ? (
        <div className={styles.qualifiers}>
          {!summary.valuation ? (
            <StatusBadge tone="warning">Aucune valorisation</StatusBadge>
          ) : summary.valuation.stale ? (
            <StatusBadge tone="warning">{`Valorisation ancienne (${summary.valuation.ageDays} jours)`}</StatusBadge>
          ) : null}
          <QualityBadge quality={summary.gainSinceOpening.quality} />
          {summary.valuation?.flags.includes('OBSERVED_TOTAL_DIFFERENCE') ? (
            <StatusBadge tone="warning">Écart au relevé à vérifier</StatusBadge>
          ) : null}
        </div>
      ) : null}
      {explaining && summary ? (
        explaining === 'gain' ? (
          <PerformanceExplanationModal
            figure="gain"
            gainSummary={{
              accountId,
              assetCode: summary.assetCode,
              gain: summary.gainSinceOpening,
            }}
            onClose={() => setExplaining(null)}
            onSource={(source) => {
              setExplaining(null);
              const href = sourceHref(accountId, summary.asOf, source.kind, source.id);
              window.history.pushState({}, '', href);
              window.dispatchEvent(new PopStateEvent('popstate'));
            }}
          />
        ) : (
          <ContractFigureExplanation
            figure={explaining}
            summary={summary}
            accountId={accountId}
            onClose={() => setExplaining(null)}
          />
        )
      ) : null}
    </section>
  );
}
