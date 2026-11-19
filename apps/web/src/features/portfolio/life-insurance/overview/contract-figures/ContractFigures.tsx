import { figureLabels, type ContractFigure } from './contractFigureLabels';
import { Fragment, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { signed } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import { QualityBadge } from '@/features/portfolio/performance/quality-badge/QualityBadge';
import { PerformanceExplanationModal } from '@/features/portfolio/performance/performance-explanation-modal/PerformanceExplanationModal';
import { ContractFigureExplanation } from './ContractFigureExplanation';
import {
  useLifeInsuranceMoney,
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './ContractFigures.module.css';
export function ContractFigures({
  summary,
  embedded = false,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  embedded?: boolean;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  const money = useLifeInsuranceMoney();
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
  // A gain that cannot be calculated stays plain text; a calculated one carries its sign and colour.
  const gain = values?.gain ? signed(money(values.gain, summary!.assetCode)) : null;
  const qualifiers = summary ? (
    <div className={styles.qualifiers}>
      {!summary.valuation ? (
        <StatusBadge tone="warning">{t('portfolio.lifeInsurance.figures.noValuation')}</StatusBadge>
      ) : summary.valuation.stale ? (
        <StatusBadge tone="warning">
          {t('portfolio.lifeInsurance.figures.staleValuation', {
            count: summary.valuation.ageDays,
          })}
        </StatusBadge>
      ) : null}
      <QualityBadge quality={summary.gainSinceOpening.quality} />
      {summary.valuation?.flags.includes('OBSERVED_TOTAL_DIFFERENCE') ? (
        <StatusBadge tone="warning">
          {t('portfolio.lifeInsurance.figures.statementGap')}
        </StatusBadge>
      ) : null}
    </div>
  ) : null;
  return (
    <section
      className={`${embedded ? '' : 'card'} ${styles.figures}`}
      aria-label={t('portfolio.lifeInsurance.figures.label')}
      aria-busy={!summary}
    >
      {(Object.keys(figureLabels) as ContractFigure[]).map((key) => (
        <Fragment key={key}>
          <KpiFigure
            label={figureLabels[key]}
            tone={key === 'gain' ? gain?.tone : undefined}
            explainLabel={t('portfolio.lifeInsurance.figures.explain', {
              label: figureLabels[key],
            })}
            size={key === 'value' ? 'display' : key === 'gain' ? 'lead' : 'compact'}
            value={
              values
                ? key === 'value' && !summary?.valuation
                  ? '-'
                  : key === 'gain' && gain
                    ? gain.text
                    : money(values[key], summary!.assetCode)
                : null
            }
            note={
              key === 'value' && summary?.valuation
                ? t('portfolio.lifeInsurance.figures.valuationOf', {
                    date: formatCalendarNumericDay(summary.valuation.valuedOn),
                  })
                : undefined
            }
            onExplain={
              summary
                ? () => {
                    if (sourceReturnFocus)
                      sourceReturnFocus.current =
                        document.activeElement instanceof HTMLElement
                          ? document.activeElement
                          : null;
                    setExplaining(key);
                  }
                : null
            }
          />
          {key === 'gain' ? qualifiers : null}
        </Fragment>
      ))}
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
            value={
              explaining === 'value' && !summary.valuation
                ? '-'
                : money(values![explaining], summary.assetCode)
            }
            summary={summary}
            accountId={accountId}
            onClose={() => setExplaining(null)}
          />
        )
      ) : null}
    </section>
  );
}
