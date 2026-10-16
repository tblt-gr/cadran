import type { PortfolioPerformance } from '@cadran/api-client';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';
import styles from './PerformanceExplanationModal.module.css';

const priceQualities = {
  CURRENT: 'Prix actuels',
  STALE: 'Prix anciens',
  NOT_DETAILED: 'Valorisation non détaillée',
} as const;

type Freshness = PortfolioPerformance['gain']['freshness']['start'];
type Fact = [label: string, value: string];

function anchorLine(
  title: string,
  anchor: PortfolioPerformance['start'],
  freshness: Freshness,
  assetCode: string,
) {
  if (!anchor) return `${title} : aucune valorisation`;
  const age = freshness
    ? `, ${freshness.ageDays} jours, ${freshness.priceQuality ? priceQualities[freshness.priceQuality] : 'sans prix'}`
    : '';
  return `${title} : ${formatCalendarNumericDay(anchor.date)}, ${formatAmount(anchor.totalValue, assetCode, 'fr')}${age}`;
}

function Facts({ facts }: { facts: Fact[] }) {
  return (
    <dl className={styles.facts}>
      {facts.map(([label, value]) => (
        <div className={styles.fact} key={label}>
          <dt>{label}</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function Remaining({ count, shown }: { count: number; shown: number }) {
  return count > shown ? <p className={styles.muted}>{`+${count - shown} autres`}</p> : null;
}

function Table({ caption, head, rows }: { caption: string; head: string[]; rows: string[][] }) {
  return (
    <div className={styles.scroll} role="region" aria-label={caption} tabIndex={0}>
      <table className={styles.table}>
        <caption>{caption}</caption>
        <thead>
          <tr>
            {head.map((label) => (
              <th key={label} scope="col">
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index}>
              {row.map((cell, cellIndex) => (
                <td key={cellIndex}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function methodFacts(performance: PortfolioPerformance, key: FigureKey): Fact[] {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, 'fr');
  switch (key) {
    case 'gain': {
      const { inputs, decomposition } = performance.gain;
      return [
        ['Valeur initiale', money(inputs.startValue)],
        ['Valeur finale', money(inputs.endValue)],
        ['Versements nets', money(inputs.netFlows)],
        ...(decomposition.status === 'COMPLETE'
          ? ([
              ['Réalisé', money(decomposition.realized)],
              ['Variation latente', money(decomposition.unrealizedChange)],
              ['Revenus', money(decomposition.income)],
              ['Frais', money(decomposition.fees)],
              ['Taxes', money(decomposition.taxes)],
              ['Écart', money(decomposition.residual)],
            ] as Fact[])
          : []),
      ];
    }
    case 'modifiedDietz': {
      const { inputs } = performance.modifiedDietz;
      return [
        ['Valeur initiale', money(inputs.startValue)],
        ['Gain', money(inputs.gain)],
        ['Durée', inputs.periodDays === null ? '-' : `${inputs.periodDays} jours`],
        ['Capital moyen', money(inputs.averageCapital)],
        ['Capital minimal accepté', formatRatioPercentage(inputs.minCapitalRatio, 'fr')],
      ];
    }
    case 'timeWeighted': {
      const { inputs } = performance.timeWeighted;
      return [
        ['Dates de découpage', String(inputs.breakpointCount)],
        ['Sous-périodes', String(inputs.subperiodCount)],
      ];
    }
    case 'moneyWeighted': {
      const { inputs } = performance.moneyWeighted;
      return [
        ['Précision de calcul', `${inputs.workingScale} décimales`],
        ['Largeur d’arrêt', inputs.stopWidth],
        ['Itérations maximales', String(inputs.maxIterations)],
        [
          'Facteur journalier cherché',
          `${inputs.searchRange[0].replace('.', ',')} à ${inputs.searchRange[1].replace('.', ',')}`,
        ],
        ['Jours de flux', String(inputs.flowDayCount)],
      ];
    }
  }
}

function MethodTable({
  performance,
  figure,
}: {
  performance: PortfolioPerformance;
  figure: FigureKey;
}) {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, 'fr');
  if (figure === 'modifiedDietz') {
    const { weights, weightCount } = performance.modifiedDietz;
    if (weights.length === 0) return null;
    return (
      <>
        <Table
          caption="Flux pondérés"
          head={['Date', 'Montant', 'Jours restants', 'Poids']}
          rows={weights.map((flow) => [
            formatCalendarNumericDay(flow.date),
            money(flow.amount),
            flow.remainingDays,
            formatRatioPercentage(flow.weight, 'fr'),
          ])}
        />
        <Remaining count={weightCount} shown={weights.length} />
      </>
    );
  }
  if (figure === 'timeWeighted') {
    const { subperiods, subperiodCount } = performance.timeWeighted;
    if (subperiods.length === 0) return null;
    return (
      <>
        <Table
          caption="Sous-périodes"
          head={['Du', 'Au', 'Valeur initiale', 'Valeur finale', 'Flux', 'Rendement']}
          rows={subperiods.map((sub) => [
            formatCalendarNumericDay(sub.from),
            formatCalendarNumericDay(sub.to),
            money(sub.startValue),
            money(sub.endValue),
            money(sub.flow),
            sub.value === null ? '-' : formatRatioPercentage(sub.value, 'fr'),
          ])}
        />
        <Remaining count={subperiodCount} shown={subperiods.length} />
      </>
    );
  }
  if (figure === 'moneyWeighted') {
    const { cashFlows, cashFlowCount } = performance.moneyWeighted;
    if (cashFlows.length === 0) return null;
    return (
      <>
        <Table
          caption="Flux investisseur"
          head={['Date', 'Montant']}
          rows={cashFlows.map((flow) => [formatCalendarNumericDay(flow.date), money(flow.amount)])}
        />
        <Remaining count={cashFlowCount} shown={cashFlows.length} />
      </>
    );
  }
  return null;
}

/** The anchors, the named inputs of the method and the list the method works from. */
export function ExplanationEntries({
  performance,
  figure,
}: {
  performance: PortfolioPerformance;
  figure: FigureKey;
}) {
  const { freshness } = performance[figure];
  return (
    <div className={styles.content}>
      <ul>
        <li>{anchorLine('Début', performance.start, freshness.start, performance.assetCode)}</li>
        <li>{anchorLine('Fin', performance.end, freshness.end, performance.assetCode)}</li>
      </ul>
      <Facts facts={methodFacts(performance, figure)} />
      <MethodTable figure={figure} performance={performance} />
    </div>
  );
}
