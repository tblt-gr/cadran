import { GainExplanationEntries } from './GainExplanationEntries';
import type {
  PortfolioGainFigure,
  PortfolioOverviewSource,
  PortfolioPerformance,
} from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  figureLabels,
  methodSentences,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import { formulaText, gainFormulaText } from '@/features/portfolio/performance/performanceFormulas';
import { describeReason } from '@/features/portfolio/performance/performanceReasons';
import { QualityBadge } from '@/features/portfolio/performance/quality-badge/QualityBadge';
import { ExplanationEntries } from './ExplanationEntries';
import { ExplanationFigure } from '@/features/portfolio/explanation/ExplanationFigure';
import { ExplanationSources } from '@/features/portfolio/explanation/ExplanationSources';
import explanation from '@/features/portfolio/explanation/Explanation.module.css';
import { FigureValue } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import styles from './PerformanceExplanationModal.module.css';

/**
 * One figure explained from the response that gave its value: the value first, how it is
 * computed, what weakens it, then the detailed inputs and sources folded away.
 */
type CommonProps = { onClose: () => void; onSource: (source: PortfolioOverviewSource) => void };
type GainSummary = { accountId: string; assetCode: string; gain: PortfolioGainFigure };
export function PerformanceExplanationModal(
  props: CommonProps &
    (
      | { performance: PortfolioPerformance; figure: FigureKey }
      | { gainSummary: GainSummary; figure: 'gain' }
    ),
) {
  const { figure, onClose, onSource } = props;
  const performance = 'performance' in props ? props.performance : null;
  const gainSummary = 'gainSummary' in props ? props.gainSummary : null;
  const data = performance ? performance[figure] : gainSummary!.gain;
  const label = figureLabels[figure];
  const context = {
    accountId: performance?.accountId ?? gainSummary!.accountId,
    assetCode: performance?.assetCode ?? gainSummary!.assetCode,
    residual: (performance?.gain ?? gainSummary!.gain).decomposition.residual,
  };
  return (
    <Modal close={onClose} title={label}>
      <div className={explanation.content}>
        <ExplanationFigure
          meta={
            <>
              <span>
                {data.from && data.to
                  ? `Du ${formatCalendarNumericDay(data.from)} au ${formatCalendarNumericDay(data.to)}`
                  : 'Période indisponible'}
              </span>
              <QualityBadge quality={data.quality} />
            </>
          }
          value={
            data.value === null ? (
              'Non calculable'
            ) : (
              <FigureValue assetCode={context.assetCode} figure={data} />
            )
          }
        />
        <section className={explanation.block}>
          <h3>Calcul</h3>
          <p>{`${methodSentences[figure]} Net de frais et de taxes.`}</p>
          <p className={styles.formula} data-testid="formula">
            {performance
              ? formulaText(performance, figure)
              : gainFormulaText(gainSummary!.gain, gainSummary!.assetCode)}
          </p>
        </section>
        {data.reasons.length ? (
          <ul className={explanation.warnings}>
            {data.reasons.map((reason, index) => {
              const { sentence, action } = describeReason(reason, context);
              return (
                <li key={`${reason.code}-${index}`}>
                  <span>{sentence}</span>
                  {action ? (
                    <a
                      className="text-action"
                      href={action.href}
                      onClick={(event) => {
                        onClose();
                        handleClientNavigation(event, action.href);
                      }}
                    >
                      {action.label}
                    </a>
                  ) : null}
                </li>
              );
            })}
          </ul>
        ) : null}
        <details className={explanation.sources}>
          <summary>Détail du calcul</summary>
          <div className={styles.entries}>
            {performance ? (
              <ExplanationEntries figure={figure} performance={performance} />
            ) : (
              <GainExplanationEntries gain={gainSummary!.gain} assetCode={gainSummary!.assetCode} />
            )}
          </div>
        </details>
        <ExplanationSources
          more={data.sourceCount - data.sources.length}
          onSource={onSource}
          sources={data.sources}
        />
        <p className={explanation.footnote}>{`Méthode version ${data.methodVersion}`}</p>
      </div>
    </Modal>
  );
}
