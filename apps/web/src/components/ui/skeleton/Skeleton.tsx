import type { HTMLAttributes } from 'react';
import styles from './Skeleton.module.css';

type SkeletonBlockProps = Omit<HTMLAttributes<HTMLDivElement>, 'children'>;

/** Decorative animated surface shared by all query-loading layouts. */
export function SkeletonBlock({ className, ...props }: SkeletonBlockProps) {
  return (
    <div
      aria-hidden="true"
      className={[styles.block, className].filter(Boolean).join(' ')}
      data-skeleton
      {...props}
    />
  );
}

interface SkeletonTextProps {
  className?: string;
  lines?: number;
}

export function SkeletonText({ className, lines = 3 }: SkeletonTextProps) {
  return (
    <div aria-hidden="true" className={[styles.text, className].filter(Boolean).join(' ')}>
      {Array.from({ length: lines }, (_, index) => (
        <SkeletonBlock
          className={[
            styles.line,
            index === lines - 1 ? styles.lineShort : index === 1 ? styles.lineMedium : '',
          ]
            .filter(Boolean)
            .join(' ')}
          key={index}
        />
      ))}
    </div>
  );
}

interface SkeletonTitleProps {
  className?: string;
  size?: 'page' | 'card' | 'eyebrow';
}

/** Stands in for a heading (or an eyebrow label) at the height of its final font size. */
export function SkeletonTitle({ className, size = 'card' }: SkeletonTitleProps) {
  return (
    <SkeletonBlock
      className={[styles.title, styles[`title_${size}`], className].filter(Boolean).join(' ')}
    />
  );
}

/** Stands in for a text or icon-plus-text button at its final control height. */
export function SkeletonButton({ className }: { className?: string }) {
  return <SkeletonBlock className={[styles.button, className].filter(Boolean).join(' ')} />;
}

/**
 * Stands in for a form control whose value is pending. A known `label` is
 * rendered for real; without one, a block holds the label's place.
 */
export function SkeletonField({ className, label }: { className?: string; label?: string }) {
  return (
    <div
      aria-hidden={label ? undefined : 'true'}
      className={[styles.field, className].filter(Boolean).join(' ')}
    >
      {label ? (
        <span className={styles.fieldLabelText}>{label}</span>
      ) : (
        <SkeletonBlock className={styles.fieldLabel} />
      )}
      <SkeletonBlock className={styles.fieldControl} />
    </div>
  );
}

interface SkeletonTableRowsProps {
  columns?: number;
  rows?: number;
}

export function SkeletonTableRows({ columns = 1, rows = 3 }: SkeletonTableRowsProps) {
  return (
    <tbody aria-hidden="true">
      {Array.from({ length: rows }, (_, row) => (
        <tr key={row}>
          {Array.from({ length: columns }, (_, column) => (
            <td key={column}>
              <SkeletonBlock className={styles.tableCell} />
            </td>
          ))}
        </tr>
      ))}
    </tbody>
  );
}

export function SkeletonKpiCard({ className }: { className?: string }) {
  return (
    <div aria-hidden="true" className={[styles.kpiCard, className].filter(Boolean).join(' ')}>
      <SkeletonBlock className={styles.kpiLabel} />
      <SkeletonBlock className={styles.kpiValue} />
    </div>
  );
}

export function SkeletonChartArea({ className }: { className?: string }) {
  return <SkeletonBlock className={[styles.chartArea, className].filter(Boolean).join(' ')} />;
}

/** A loading announcement is intentionally opt-in so a screen exposes exactly one. */
export function SkeletonStatus({ children }: { children: string }) {
  return (
    <p aria-live="polite" className="sr-only" role="status">
      {children}
    </p>
  );
}
