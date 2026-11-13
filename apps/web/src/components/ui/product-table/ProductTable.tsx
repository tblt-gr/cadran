import type { ReactNode } from 'react';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './ProductTable.module.css';

export interface ProductTableHeading {
  /** The text of the column; for a column without a visible title, its accessible name. */
  label: ReactNode;
  /** The column carries only actions: its title is kept for assistive technology. */
  hidden?: boolean;
}

/**
 * The one plate and table shared by the system catalogue and the product models, so both lists
 * keep the same rhythm: name, family, yield, then what is specific to the page.
 */
export function ProductTablePanel({
  caption,
  headings,
  children,
  busy = false,
  status,
  testId,
}: {
  caption: string;
  headings: readonly ProductTableHeading[];
  children: ReactNode;
  /** Pending read: the plate is busy and announces `status` once. */
  busy?: boolean;
  status?: string;
  testId?: string;
}) {
  return (
    <div aria-busy={busy || undefined} className={`card ${styles.panel}`} data-testid={testId}>
      {busy && status ? <SkeletonStatus>{status}</SkeletonStatus> : null}
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{caption}</caption>
          <thead>
            <tr>
              {headings.map((heading, index) => (
                <th key={index} scope="col">
                  {heading.hidden ? (
                    <span className="sr-only">{heading.label}</span>
                  ) : (
                    heading.label
                  )}
                </th>
              ))}
            </tr>
          </thead>
          {children}
        </table>
      </div>
    </div>
  );
}

/** First cell of a row: the name, with one muted line under it. */
export function ProductNameCell({ name, note }: { name: ReactNode; note: ReactNode }) {
  return (
    <th scope="row">
      <span>{name}</span>
      <small>{note}</small>
    </th>
  );
}

/** A cell with its value and one muted line under it. */
export function ProductPairCell({ value, note }: { value: ReactNode; note: ReactNode }) {
  return (
    <td>
      {value}
      <small className={styles.sub}>{note}</small>
    </td>
  );
}
