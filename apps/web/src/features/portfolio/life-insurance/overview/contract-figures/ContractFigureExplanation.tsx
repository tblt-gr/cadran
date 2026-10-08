import { Modal } from '@/components/ui/modal/Modal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  money,
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { figureLabels, type ContractFigure } from './contractFigureLabels';
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
  summary,
  accountId,
  onClose,
}: {
  figure: Exclude<ContractFigure, 'gain'>;
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
  return (
    <Modal close={onClose} title={`Expliquer : ${figureLabels[figure]}`}>
      <div className={styles.content}>
        <section>
          <h3>Formule</h3>
          <p>
            {figure === 'value' && valuation?.detail === 'TOTAL'
              ? 'Total observé de la valorisation retenue. La répartition n’est pas détaillée.'
              : formulas[figure]}
          </p>
        </section>
        <section>
          <h3>Périmètre et période</h3>
          <p>
            Ce contrat uniquement.{' '}
            {figure === 'value'
              ? valuation
                ? `Valorisation du ${formatCalendarNumericDay(valuation.valuedOn)}.`
                : 'Aucune valorisation.'
              : `Depuis la souscription ${summary.contract ? `du ${formatCalendarNumericDay(summary.contract.subscribedOn)}` : ''} au ${formatCalendarNumericDay(summary.asOf)}.`}
          </p>
        </section>
        <section>
          <h3>Entrées enregistrées</h3>
          <dl>
            {facts.map(([label, value]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd className="money">{amount(value)}</dd>
              </div>
            ))}
          </dl>
        </section>
        <section>
          <h3>Qualité</h3>
          <p>
            {figure === 'value'
              ? !valuation
                ? 'Aucune valorisation'
                : valuation.stale
                  ? `Valorisation ancienne (${valuation.ageDays} jours)`
                  : 'Valorisation complète'
              : 'Montants enregistrés, sans projection ni estimation.'}
          </p>
          {summary.qualityIssues.length ? (
            <p>Consultez les points de qualité de la synthèse.</p>
          ) : null}
        </section>
        <section>
          <h3>Sources</h3>
          {sources.length ? (
            <ul>
              {sources.map((source) => {
                const href = sourceHref(accountId, summary.asOf, source.kind, source.id);
                return (
                  <li key={`${source.kind}-${source.id}`}>
                    <a
                      href={href}
                      onClick={(event) => {
                        onClose();
                        handleClientNavigation(event, href);
                      }}
                    >
                      {source.kind === 'OPERATION' ? 'Opération' : 'Valorisation'} du{' '}
                      {formatCalendarNumericDay(source.date)}
                    </a>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p>Aucune source à cette date.</p>
          )}
          {count > sources.length ? <p>{count - sources.length} autres sources</p> : null}
        </section>
      </div>
    </Modal>
  );
}
