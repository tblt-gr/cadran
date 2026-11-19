import { useTranslation } from 'react-i18next';
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
  figureLabelKeys,
  methodSentenceKeys,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import { formulaText, gainFormulaText } from '@/features/portfolio/performance/performanceFormulas';
import {
  describeReason,
  formatReasonDates,
  listedDateLimit,
  summarizeReason,
} from '@/features/portfolio/performance/performanceReasons';
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
  const { t, i18n } = useTranslation();
  const { figure, onClose, onSource } = props;
  const performance = 'performance' in props ? props.performance : null;
  const gainSummary = 'gainSummary' in props ? props.gainSummary : null;
  const data = performance ? performance[figure] : gainSummary!.gain;
  const label = t(figureLabelKeys[figure]);
  const context = {
    accountId: performance?.accountId ?? gainSummary!.accountId,
    assetCode: performance?.assetCode ?? gainSummary!.assetCode,
    residual: (performance?.gain ?? gainSummary!.gain).decomposition.residual,
    t,
    locale: i18n.language,
  };
  return (
    <Modal intent="detail" close={onClose} title={label}>
      <div className={explanation.content}>
        <ExplanationFigure
          meta={
            <>
              <span>
                {data.from && data.to
                  ? t('portfolio.performance.modal.period', {
                      from: formatCalendarNumericDay(data.from),
                      to: formatCalendarNumericDay(data.to),
                    })
                  : t('portfolio.performance.modal.periodUnavailable')}
              </span>
              <QualityBadge quality={data.quality} />
            </>
          }
          value={
            data.value === null ? (
              t('states.notCalculable.label')
            ) : (
              <FigureValue assetCode={context.assetCode} figure={data} />
            )
          }
        />
        <section className={explanation.block}>
          <h3>{t('portfolio.performance.modal.calculation')}</h3>
          <p>
            {t('portfolio.performance.modal.method', { method: t(methodSentenceKeys[figure]) })}
          </p>
          <p className={styles.formula} data-testid="formula">
            {performance
              ? formulaText(performance, figure, t, i18n.language)
              : gainFormulaText(gainSummary!.gain, gainSummary!.assetCode, t, i18n.language)}
          </p>
        </section>
        {data.reasons.length ? (
          <ul className={explanation.warnings}>
            {data.reasons.map((reason, index) => {
              const { sentence, action } = describeReason(reason, context);
              const crowded = reason.dates.length > listedDateLimit;
              return (
                <li key={`${reason.code}-${index}`}>
                  <span>{crowded ? summarizeReason(reason, context) : sentence}</span>
                  {crowded ? (
                    <details className={styles.dates}>
                      <summary>
                        {t('portfolio.performance.modal.showDates', { count: reason.dates.length })}
                      </summary>
                      <p>{formatReasonDates(reason.dates)}</p>
                    </details>
                  ) : null}
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
          <summary>{t('portfolio.performance.modal.detail')}</summary>
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
        <p className={explanation.footnote}>
          {t('portfolio.performance.modal.methodVersion', { version: data.methodVersion })}
        </p>
      </div>
    </Modal>
  );
}
