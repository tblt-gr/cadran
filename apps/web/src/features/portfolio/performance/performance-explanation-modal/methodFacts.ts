import type { PortfolioPerformance } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';

export type Fact = [label: string, value: string];

type FactKey =
  | 'startValue'
  | 'endValue'
  | 'netFlows'
  | 'realized'
  | 'unrealizedChange'
  | 'income'
  | 'fees'
  | 'taxes'
  | 'residual'
  | 'gain'
  | 'duration'
  | 'averageCapital'
  | 'minCapital'
  | 'breakpoints'
  | 'subperiods'
  | 'precision'
  | 'stopWidth'
  | 'maxIterations'
  | 'searchedFactor'
  | 'flowDays';

/** Decimal comma in French, decimal point elsewhere, read from the language itself. */
function localizedDecimal(value: string, locale: string): string {
  const separator = new Intl.NumberFormat(locale)
    .formatToParts(1.1)
    .find((p) => p.type === 'decimal');
  return value.replace('.', separator?.value ?? '.');
}

export function methodFacts(
  performance: PortfolioPerformance,
  key: FigureKey,
  t: TFunction,
  locale: string,
): Fact[] {
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, locale);
  const label = (factKey: FactKey) => t(`portfolio.performance.facts.${factKey}`);
  switch (key) {
    case 'gain': {
      const { inputs, decomposition } = performance.gain;
      return [
        [label('startValue'), money(inputs.startValue)],
        [label('endValue'), money(inputs.endValue)],
        [label('netFlows'), money(inputs.netFlows)],
        ...(decomposition.status === 'COMPLETE'
          ? ([
              [label('realized'), money(decomposition.realized)],
              [label('unrealizedChange'), money(decomposition.unrealizedChange)],
              [label('income'), money(decomposition.income)],
              [label('fees'), money(decomposition.fees)],
              [label('taxes'), money(decomposition.taxes)],
              [label('residual'), money(decomposition.residual)],
            ] as Fact[])
          : []),
      ];
    }
    case 'modifiedDietz': {
      const { inputs } = performance.modifiedDietz;
      return [
        [label('startValue'), money(inputs.startValue)],
        [label('gain'), money(inputs.gain)],
        [
          label('duration'),
          inputs.periodDays === null
            ? '-'
            : t('portfolio.performance.facts.days', { count: inputs.periodDays }),
        ],
        [label('averageCapital'), money(inputs.averageCapital)],
        [label('minCapital'), formatRatioPercentage(inputs.minCapitalRatio, locale)],
      ];
    }
    case 'timeWeighted': {
      const { inputs } = performance.timeWeighted;
      return [
        [label('breakpoints'), String(inputs.breakpointCount)],
        [label('subperiods'), String(inputs.subperiodCount)],
      ];
    }
    case 'moneyWeighted': {
      const { inputs } = performance.moneyWeighted;
      return [
        [
          label('precision'),
          t('portfolio.performance.facts.decimals', { count: inputs.workingScale }),
        ],
        [label('stopWidth'), inputs.stopWidth],
        [label('maxIterations'), String(inputs.maxIterations)],
        [
          label('searchedFactor'),
          t('portfolio.performance.facts.searchedRange', {
            from: localizedDecimal(inputs.searchRange[0], locale),
            to: localizedDecimal(inputs.searchRange[1], locale),
          }),
        ],
        [label('flowDays'), String(inputs.flowDayCount)],
      ];
    }
  }
}
