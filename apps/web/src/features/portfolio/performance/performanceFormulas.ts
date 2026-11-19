import type { PortfolioGainFigure, PortfolioPerformance } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import type { FigureKey } from './performanceFigures';

/** The method written out with the period figures of the response; nothing is recomputed here. */
export function formulaText(
  performance: PortfolioPerformance,
  key: FigureKey,
  t: TFunction,
  locale: string,
): string {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, locale);
  const rate = (value: string | null) =>
    value === null ? '-' : formatRatioPercentage(value, locale);
  switch (key) {
    case 'gain':
      return gainFormulaText(performance.gain, performance.assetCode, t, locale);
    case 'modifiedDietz': {
      const { inputs, value } = performance.modifiedDietz;
      if (inputs.gain === null) {
        return t('portfolio.performance.formula.chained', { rate: rate(value) });
      }
      return t('portfolio.performance.formula.modifiedDietz', {
        gain: money(inputs.gain),
        capital: money(inputs.averageCapital),
        rate: rate(value),
        period:
          inputs.periodDays === null
            ? t('portfolio.performance.formula.daysUnknown')
            : t('portfolio.performance.formula.days', { count: inputs.periodDays }),
      });
    }
    case 'timeWeighted': {
      const { inputs, value } = performance.timeWeighted;
      return t('portfolio.performance.formula.timeWeighted', {
        count: inputs.subperiodCount,
        rate: rate(value),
      });
    }
    case 'moneyWeighted': {
      const figure = performance.moneyWeighted;
      return t('portfolio.performance.formula.moneyWeighted', {
        formula: figure.formula,
        rate: rate(figure.annualizedRate),
      });
    }
  }
}

export function gainFormulaText(
  gain: PortfolioGainFigure,
  assetCode: string,
  t: TFunction,
  locale: string,
): string {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, assetCode, locale);
  // Since the opening there is no value before the first flow: the term is left out, not zeroed.
  const start =
    gain.inputs.startValue === null && gain.value !== null
      ? ''
      : t('portfolio.performance.formula.gainStart', { start: money(gain.inputs.startValue) });
  return t('portfolio.performance.formula.gain', {
    end: money(gain.inputs.endValue),
    start,
    flows: money(gain.inputs.netFlows),
    gain: money(gain.value),
  });
}
