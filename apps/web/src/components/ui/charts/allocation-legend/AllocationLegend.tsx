import type { ReactNode } from 'react';
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
}

/** Groups as a list: label, share, amount and a decorative bar, coloured like the donut slices. */
export function AllocationLegend({ items, label }: AllocationLegendProps) {
  return (
    <ul aria-label={label} className={styles.list}>
      {items.map((item) => (
        <li key={item.key}>
          <span className={styles.name}>{item.label}</span>
          <span className={styles.share}>{item.share}</span>
          <strong className={`money ${styles.amount}`}>{item.amount}</strong>
          <AllocationBar percent={item.barPercent} />
        </li>
      ))}
    </ul>
  );
}
