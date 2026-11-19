import { lazyLabels } from '@/features/portfolio/lazyLabels';
const figureIds = ['value', 'gain', 'premiums', 'surrenders', 'fees'] as const;
export type ContractFigure = (typeof figureIds)[number];
/** The value and its result lead, the cumulated flows and the fees follow. */
export const figureLabels = lazyLabels<ContractFigure>(
  figureIds,
  'portfolio.lifeInsurance.figures.labels',
);
