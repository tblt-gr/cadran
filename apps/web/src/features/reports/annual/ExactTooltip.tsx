import styles from './AnnualCharts.module.css';

interface ExactTooltipProps {
  active?: boolean;
  label?: string | number;
  /** Exact, already formatted lines for the hovered or focused month. */
  linesFor: (label: string) => string[];
}

/** The plotted numbers are scaled for geometry; the tooltip shows the backend's exact figures instead. */
export function ExactTooltip({ active, label, linesFor }: ExactTooltipProps) {
  if (!active || label === undefined) return null;

  return (
    <div className={styles.tooltip} role="status">
      {linesFor(String(label)).map((line) => (
        <p key={line}>{line}</p>
      ))}
    </div>
  );
}
