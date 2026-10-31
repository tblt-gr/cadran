import type { PortfolioGainFigure, PortfolioPerformance } from '@cadran/api-client';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import type { FigureKey } from './performanceFigures';

/** The method written out with the period figures of the response; nothing is recomputed here. */
export function formulaText(performance: PortfolioPerformance, key: FigureKey): string {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, 'fr');
  const rate = (value: string | null) =>
    value === null ? '-' : formatRatioPercentage(value, 'fr');
  switch (key) {
    case 'gain':
      return gainFormulaText(performance.gain, performance.assetCode);
    case 'modifiedDietz': {
      const { inputs, value } = performance.modifiedDietz;
      if (inputs.gain === null) {
        return `Rendements mensuels enchaînés : (1 + r1) × (1 + r2) × … − 1 = ${rate(value)}`;
      }
      return `Rendement = gain ÷ capital moyen = ${money(inputs.gain)} ÷ ${money(inputs.averageCapital)} = ${rate(value)}, sur ${inputs.periodDays ?? '-'} jours`;
    }
    case 'timeWeighted': {
      const { inputs, value } = performance.timeWeighted;
      return `Rendement = (1 + r1) × (1 + r2) × … − 1 sur ${inputs.subperiodCount} sous-périodes = ${rate(value)}`;
    }
    case 'moneyWeighted': {
      const figure = performance.moneyWeighted;
      return `${figure.formula} ; taux annualisé = ${rate(figure.annualizedRate)}`;
    }
  }
}

export function gainFormulaText(gain: PortfolioGainFigure, assetCode: string): string {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, assetCode, 'fr');
  // Since the opening there is no value before the first flow: the term is left out, not zeroed.
  const start =
    gain.inputs.startValue === null && gain.value !== null
      ? ''
      : ` − ${money(gain.inputs.startValue)}`;
  return `Gain = ${money(gain.inputs.endValue)}${start} − ${money(gain.inputs.netFlows)} = ${money(gain.value)}`;
}
