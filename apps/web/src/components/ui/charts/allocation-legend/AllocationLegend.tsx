import { useState, type ReactNode } from 'react';
import { AllocationBar } from './AllocationBar';
import styles from './AllocationLegend.module.css';

export interface AllocationLegendDetail {
  amount: ReactNode;
  key: string;
  label: string;
  share: ReactNode;
}

export interface AllocationLegendItem {
  /** Formatted exact amount, or an empty-value marker. */
  amount: ReactNode;
  key: string;
  label: string;
  /** Decimal percent (0-100) driving the bar width; null draws an empty track. */
  barPercent: string | null;
  /** Formatted share text, or an empty-value marker. */
  share: ReactNode;
  /** What this entry aggregates; when present the label becomes a disclosure that lists them. */
  details?: AllocationLegendDetail[];
}

interface AllocationLegendProps {
  items: AllocationLegendItem[];
  label: string;
}

function LegendName({ item }: { item: AllocationLegendItem }) {
  const [open, setOpen] = useState(false);
  if (!item.details) return <span className={styles.name}>{item.label}</span>;
  const listId = `legend-details-${item.key}`;

  return (
    <>
      <button
        aria-controls={listId}
        aria-expanded={open}
        className={`${styles.name} ${styles.toggle}`}
        onClick={() => setOpen(!open)}
        type="button"
      >
        <span aria-hidden="true">{open ? '▾' : '▸'}</span> {item.label}
      </button>
      {open ? (
        <ul className={styles.details} id={listId}>
          {item.details.map((detail) => (
            <li key={detail.key}>
              <span className={styles.name}>{detail.label}</span>
              <span className={styles.share}>{detail.share}</span>
              <span className={`money ${styles.detailAmount}`}>{detail.amount}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </>
  );
}

/** Groups as a list: label, share, amount and a decorative bar, coloured like the donut slices. */
export function AllocationLegend({ items, label }: AllocationLegendProps) {
  return (
    <ul aria-label={label} className={styles.list}>
      {items.map((item) => (
        <li key={item.key}>
          <LegendName item={item} />
          <span className={styles.share}>{item.share}</span>
          <strong className={`money ${styles.amount}`}>{item.amount}</strong>
          <AllocationBar percent={item.barPercent} />
        </li>
      ))}
    </ul>
  );
}
