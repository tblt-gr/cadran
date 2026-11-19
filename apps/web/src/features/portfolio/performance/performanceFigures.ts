export type FigureKey = 'gain' | 'modifiedDietz' | 'timeWeighted' | 'moneyWeighted';

export const figureKeys: readonly FigureKey[] = [
  'gain',
  'modifiedDietz',
  'timeWeighted',
  'moneyWeighted',
];

export const figureLabelKeys = {
  gain: 'portfolio.performance.figures.gain',
  modifiedDietz: 'portfolio.performance.figures.modifiedDietz',
  timeWeighted: 'portfolio.performance.figures.timeWeighted',
  moneyWeighted: 'portfolio.performance.figures.moneyWeighted',
} as const satisfies Record<FigureKey, string>;

export const methodSentenceKeys = {
  gain: 'portfolio.performance.methods.gain',
  modifiedDietz: 'portfolio.performance.methods.modifiedDietz',
  timeWeighted: 'portfolio.performance.methods.timeWeighted',
  moneyWeighted: 'portfolio.performance.methods.moneyWeighted',
} as const satisfies Record<FigureKey, string>;
