import type { ReactNode } from 'react';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { figureLabels, type FigureKey } from '@/features/portfolio/performance/performanceFigures';
import styles from './FigureCell.module.css';

/** A figure followed by the `i` button that opens its explanation; `scope` names the month or year. */
export function FigureCell({
  children,
  figure,
  scope,
  onExplain,
}: {
  children: ReactNode;
  figure: FigureKey;
  scope: string;
  onExplain: () => void;
}) {
  return (
    <span className={styles.cell}>
      {children}
      <InfoButton label={`Expliquer : ${figureLabels[figure]}, ${scope}`} onClick={onExplain} />
    </span>
  );
}
