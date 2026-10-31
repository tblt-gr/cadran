export type FigureKey = 'gain' | 'modifiedDietz' | 'timeWeighted' | 'moneyWeighted';

export const figureKeys: readonly FigureKey[] = [
  'gain',
  'modifiedDietz',
  'timeWeighted',
  'moneyWeighted',
];

export const figureLabels: Record<FigureKey, string> = {
  gain: 'Gain total',
  modifiedDietz: 'Rendement (Modified Dietz)',
  timeWeighted: 'Rendement des placements (TWR)',
  moneyWeighted: 'Rendement personnel (MWR)',
};

export const methodSentences: Record<FigureKey, string> = {
  gain: 'Valeur finale moins valeur initiale moins les versements nets.',
  modifiedDietz:
    'Approximation du rendement pondéré dans le temps : chaque flux est pondéré par la part de la période où il était investi.',
  timeWeighted:
    "Rendement des placements, neutre vis-à-vis des versements, calculé à partir d'une valorisation à chaque date de flux.",
  moneyWeighted:
    'Taux annuel de votre argent, compte tenu des dates de vos versements et retraits.',
};
