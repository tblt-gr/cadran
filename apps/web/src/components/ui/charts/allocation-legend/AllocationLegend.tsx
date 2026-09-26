import { useId, useState, type ReactNode } from 'react';
import { AllocationBar } from './AllocationBar';
import styles from './AllocationLegend.module.css';

export interface AllocationLegendItem {
  /** Formatted exact amount, or an empty-value marker. */
  amount: ReactNode;
  key: string;
  label: string;
  /** Decimal percent (0-100) driving the bar width; null draws an empty track. */
  barPercent: string | null;
  /** Formatted share text, or an empty-value marker. */
  share: ReactNode;
}

interface AllocationLegendProps {
  items: AllocationLegendItem[];
  label: string;
  /** Shows only the first rows until the toggle is pressed; hidden when nothing exceeds the count. */
  collapse?: { count: number; moreLabel: string; lessLabel: string };
}

/** Groups as a list: label, share, amount and a decorative bar, coloured like the donut slices. */
export function AllocationLegend({ collapse, items, label }: AllocationLegendProps) {
  const [expanded, setExpanded] = useState(false);
  const listId = useId();
  const limit = collapse && !expanded ? collapse.count : items.length;
  const canToggle = collapse !== undefined && items.length > collapse.count;

  return (
    <div className={styles.legend}>
      <ul aria-label={label} className={styles.list} id={listId}>
        {items.slice(0, limit).map((item) => (
          <li key={item.key}>
            <span className={styles.name}>{item.label}</span>
            <span className={styles.share}>{item.share}</span>
            <strong className={`money ${styles.amount}`}>{item.amount}</strong>
            <AllocationBar percent={item.barPercent} />
          </li>
        ))}
      </ul>
      {canToggle ? (
        <button
          aria-controls={listId}
          aria-expanded={expanded}
          className={styles.more}
          onClick={() => setExpanded(!expanded)}
          type="button"
        >
          {expanded ? collapse.lessLabel : collapse.moreLabel}
        </button>
      ) : null}
    </div>
  );
}
