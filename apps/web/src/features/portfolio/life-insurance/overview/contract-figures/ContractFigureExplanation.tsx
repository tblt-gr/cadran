import { Modal } from '@/components/ui/modal/Modal';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  money,
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { figureLabels, type ContractFigure } from './contractFigureLabels';
import { ExplanationFigure } from '@/features/portfolio/explanation/ExplanationFigure';
import { ExplanationSources } from '@/features/portfolio/explanation/ExplanationSources';
import explanation from '@/features/portfolio/explanation/Explanation.module.css';
import styles from './ContractFigureExplanation.module.css';
const formulas = {
  value:
    'Valeur du contrat = fonds en euros + unités de compte + liquidités de la valorisation retenue.',
  premiums:
    'Versements cumulés = somme des versements bruts enregistrés. Les arbitrages sont exclus.',
  surrenders:
    'Rachats nets versés = somme des montants effectivement versés pour les rachats enregistrés. Les arbitrages sont exclus.',
  fees: 'Frais cumulés = frais d’entrée + gestion + arbitrage + rachat + autres frais enregistrés.',
};
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
  const valuation = summary.valuation;
  const sources =
    figure === 'value'
      ? valuation
        ? [{ kind: 'VALUATION' as const, id: valuation.id, date: valuation.valuedOn }]
        : []
      : summary.cumulative.sources;
  const count = figure === 'value' ? sources.length : summary.cumulative.sourceCount;
  const amount = (value: string | null) => money(value, summary.assetCode);
  const cumulative = summary.cumulative;
  const facts: [string, string | null][] =
    figure === 'value'
      ? [
          ['Fonds en euros', valuation?.euroFundValue ?? null],
          ['Unités de compte', valuation?.unitLinkedValue ?? null],
          ['Liquidités', valuation?.cash ?? null],
          ['Total observé', valuation?.observedTotal ?? null],
          ['Écart au relevé', valuation?.observedTotalDifference ?? null],
          ['Tolérance', valuation?.tolerance ?? null],
        ]
      : figure === 'fees'
        ? [
            ['Entrée', cumulative.fees.ENTRY],
            ['Gestion', cumulative.fees.MANAGEMENT],
            ['Arbitrage', cumulative.fees.SWITCH],
            ['Rachat', cumulative.fees.SURRENDER],
            ['Autres', cumulative.fees.OTHER],
          ]
        : figure === 'surrenders'
          ? [
              ['Rachats bruts', cumulative.surrendersGross],
              ['Net versé', cumulative.surrendersNetPaid],
            ]
          : [['Versements bruts', cumulative.premiums]];
  const period =
    figure === 'value'
      ? valuation
        ? `Valorisation du ${formatCalendarNumericDay(valuation.valuedOn)}`
        : 'Aucune valorisation'
      : `${summary.contract ? `Du ${formatCalendarNumericDay(summary.contract.subscribedOn)}` : 'Depuis la souscription'} au ${formatCalendarNumericDay(summary.asOf)}`;
  const quality =
    figure === 'value'
      ? !valuation
        ? 'Aucune valorisation'
        : valuation.stale
          ? `Valorisation ancienne (${valuation.ageDays} jours)`
          : 'Valorisation complète'
      : 'Montants enregistrés';
  return (
    <Modal close={onClose} title={figureLabels[figure]}>
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
          <h3>Calcul</h3>
          <p>
            {figure === 'value' && valuation?.detail === 'TOTAL'
              ? 'Total observé de la valorisation retenue. La répartition n’est pas détaillée.'
              : formulas[figure]}
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
