import type { PortfolioGainFigure } from '@cadran/api-client';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './PerformanceExplanationModal.module.css';
export function GainExplanationEntries({
  gain,
  assetCode,
}: {
  gain: PortfolioGainFigure;
  assetCode: string;
}) {
  const facts: [string, string | null][] = [
    ['Valeur initiale', gain.inputs.startValue],
    ['Valeur finale', gain.inputs.endValue],
    ['Versements nets', gain.inputs.netFlows],
  ];
  if (gain.decomposition.status === 'COMPLETE')
    facts.push(
      ['Réalisé', gain.decomposition.realized],
      ['Variation latente', gain.decomposition.unrealizedChange],
      ['Revenus', gain.decomposition.income],
      ['Frais', gain.decomposition.fees],
      ['Taxes', gain.decomposition.taxes],
      ['Écart', gain.decomposition.residual],
    );
  return (
    <div className={styles.content}>
      <ul>
        {(['start', 'end'] as const).map((key) => {
          const anchor = gain.freshness[key];
          return (
            <li key={key}>
              {key === 'start' ? 'Début' : 'Fin'} :{' '}
              {anchor
                ? `${formatCalendarNumericDay(anchor.date)}, ${anchor.ageDays} jours, ${anchor.priceQuality === 'STALE' ? 'prix anciens' : anchor.priceQuality === 'CURRENT' ? 'prix actuels' : 'valorisation non détaillée'}`
                : 'Aucune valorisation'}
            </li>
          );
        })}
      </ul>
      <dl className={styles.facts}>
        {facts.map(([label, value]) => (
          <div className={styles.fact} key={label}>
            <dt>{label}</dt>
            <dd>{value === null ? 'Non calculable' : formatAmount(value, assetCode, 'fr')}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
