import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';

/** What an `i` button of the performance tab asks to explain. */
export type ExplainTarget =
  { kind: 'month'; month: string; figure: FigureKey } | { kind: 'year'; figure: FigureKey };
