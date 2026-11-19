import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  useLifeInsuranceMoney,
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { figureLabels, type ContractFigure } from './contractFigureLabels';
import { ExplanationFigure } from '@/features/portfolio/explanation/ExplanationFigure';
import { ExplanationSources } from '@/features/portfolio/explanation/ExplanationSources';
import explanation from '@/features/portfolio/explanation/Explanation.module.css';
import styles from './ContractFigureExplanation.module.css';
export function ContractFigureExplanation({
  figure,
  value,
  summary,
  accountId,
  onClose,
}: {
  figure: Exclude<ContractFigure, 'gain'>;
  /** The value as the contract figures show it. */
  value: string;
  summary: Summary;
  accountId: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const valuation = summary.valuation;
  const sources =
    figure === 'value'
      ? valuation
        ? [{ kind: 'VALUATION' as const, id: valuation.id, date: valuation.valuedOn }]
        : []
      : summary.cumulative.sources;
  const count = figure === 'value' ? sources.length : summary.cumulative.sourceCount;
  const money = useLifeInsuranceMoney();
  const amount = (value: string | null) => money(value, summary.assetCode);
  const cumulative = summary.cumulative;
  const facts: [string, string | null][] =
    figure === 'value'
      ? [
          [
            t('portfolio.lifeInsurance.allocation.kinds.EURO_FUND'),
            valuation?.euroFundValue ?? null,
          ],
          [
            t('portfolio.lifeInsurance.allocation.kinds.UNIT_LINKED'),
            valuation?.unitLinkedValue ?? null,
          ],
          [t('portfolio.lifeInsurance.allocation.kinds.CASH'), valuation?.cash ?? null],
          [t('portfolio.lifeInsurance.explain.observedTotal'), valuation?.observedTotal ?? null],
          [
            t('portfolio.lifeInsurance.explain.statementGap'),
            valuation?.observedTotalDifference ?? null,
          ],
          [t('portfolio.lifeInsurance.explain.tolerance'), valuation?.tolerance ?? null],
        ]
      : figure === 'fees'
        ? [
            [t('portfolio.lifeInsurance.explain.fees.ENTRY'), cumulative.fees.ENTRY],
            [t('portfolio.lifeInsurance.explain.fees.MANAGEMENT'), cumulative.fees.MANAGEMENT],
            [t('portfolio.lifeInsurance.explain.fees.SWITCH'), cumulative.fees.SWITCH],
            [t('portfolio.lifeInsurance.explain.fees.SURRENDER'), cumulative.fees.SURRENDER],
            [t('portfolio.lifeInsurance.explain.fees.OTHER'), cumulative.fees.OTHER],
          ]
        : figure === 'surrenders'
          ? [
              [
                t('portfolio.lifeInsurance.recorded.rows.surrendersGross'),
                cumulative.surrendersGross,
              ],
              [t('portfolio.lifeInsurance.explain.netPaid'), cumulative.surrendersNetPaid],
            ]
          : [[t('portfolio.lifeInsurance.recorded.rows.premiums'), cumulative.premiums]];
  const period =
    figure === 'value'
      ? valuation
        ? t('portfolio.lifeInsurance.figures.valuationOf', {
            date: formatCalendarNumericDay(valuation.valuedOn),
          })
        : t('portfolio.lifeInsurance.figures.noValuation')
      : t('portfolio.lifeInsurance.explain.period', {
          from: summary.contract
            ? t('portfolio.lifeInsurance.explain.fromDate', {
                date: formatCalendarNumericDay(summary.contract.subscribedOn),
              })
            : t('portfolio.lifeInsurance.explain.sinceSubscription'),
          to: formatCalendarNumericDay(summary.asOf),
        });
  const quality =
    figure === 'value'
      ? !valuation
        ? t('portfolio.lifeInsurance.figures.noValuation')
        : valuation.stale
          ? t('portfolio.lifeInsurance.figures.staleValuation', { count: valuation.ageDays })
          : t('portfolio.lifeInsurance.explain.completeValuation')
      : t('portfolio.lifeInsurance.explain.recordedAmounts');
  return (
    <Modal intent="detail" close={onClose} title={figureLabels[figure]}>
      <div className={explanation.content}>
        <ExplanationFigure
          meta={
            <>
              <span>{period}</span>
              <span aria-hidden="true">·</span>
              <span>{quality}</span>
            </>
          }
          value={value}
        />
        <section className={explanation.block}>
          <h3>{t('portfolio.lifeInsurance.explain.calculation')}</h3>
          <p>
            {figure === 'value' && valuation?.detail === 'TOTAL'
              ? t('portfolio.lifeInsurance.explain.totalOnly')
              : t(`portfolio.lifeInsurance.explain.formulas.${figure}`)}
          </p>
          <dl className={styles.facts}>
            {facts.map(([label, fact]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd className="money">{amount(fact)}</dd>
              </div>
            ))}
          </dl>
        </section>
        <ExplanationSources
          hrefOf={(source) => sourceHref(accountId, summary.asOf, source.kind, source.id)}
          more={count - sources.length}
          onSource={(source) => {
            onClose();
            const href = sourceHref(accountId, summary.asOf, source.kind, source.id);
            window.history.pushState({}, '', href);
            window.dispatchEvent(new PopStateEvent('popstate'));
          }}
          sources={sources}
        />
      </div>
    </Modal>
  );
}
