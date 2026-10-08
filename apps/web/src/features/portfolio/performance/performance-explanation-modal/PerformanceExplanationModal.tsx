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
import { ExplanationSection } from './ExplanationSection';
import styles from './PerformanceExplanationModal.module.css';

/** Method, formula, inputs, quality and sources of one figure, all from the response that gave its value. */
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
    <Modal close={onClose} title={`Expliquer : ${label}`}>
      <div className={styles.content}>
        <ExplanationSection title="Méthode">
          <p>{label}</p>
          <p>{methodSentences[figure]}</p>
          <p className={styles.muted}>{`Version ${data.methodVersion}`}</p>
        </ExplanationSection>
        <ExplanationSection title="Formule">
          <p className={styles.formula} data-testid="formula">
            {performance
              ? formulaText(performance, figure)
              : gainFormulaText(gainSummary!.gain, gainSummary!.assetCode)}
          </p>
        </ExplanationSection>
        <ExplanationSection title="Période">
          <p>
            {data.from && data.to
              ? `Du ${formatCalendarNumericDay(data.from)} au ${formatCalendarNumericDay(data.to)}, ${data.days} jours`
              : 'Période indisponible'}
          </p>
        </ExplanationSection>
        <ExplanationSection title="Entrées">
          <>
            {performance ? (
              <ExplanationEntries figure={figure} performance={performance} />
            ) : (
              <GainExplanationEntries gain={gainSummary!.gain} assetCode={gainSummary!.assetCode} />
            )}
          </>
        </ExplanationSection>
        <ExplanationSection title="Politique de coûts">
          <p>Net de frais et de taxes</p>
        </ExplanationSection>
        <ExplanationSection title="Qualité">
          <div className={styles.reason}>
            <div>
              <QualityBadge quality={data.quality} />
            </div>
            {data.reasons.length ? (
              <ul>
                {data.reasons.map((reason, index) => {
                  const { sentence, action } = describeReason(reason, context);
                  return (
                    <li key={`${reason.code}-${index}`}>
                      <p>{sentence}</p>
                      {action ? (
                        <a
                          className="secondary-action"
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
          </div>
        </ExplanationSection>
        <ExplanationSection title="Sources">
          {data.sources.length ? (
            <ul>
              {data.sources.map((source) => (
                <li key={`${source.kind}-${source.id}`}>
                  <button
                    className="secondary-action"
                    onClick={() => onSource(source)}
                    type="button"
                  >
                    {`${source.kind === 'OPERATION' ? 'Opération' : 'Valorisation'} du ${formatCalendarNumericDay(source.date)}`}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p>Aucune source.</p>
          )}
          {data.sourceCount > data.sources.length ? (
            <p className={styles.muted}>{`+${data.sourceCount - data.sources.length} autres`}</p>
          ) : null}
        </ExplanationSection>
      </div>
    </Modal>
  );
}
