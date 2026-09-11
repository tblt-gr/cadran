import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './Disclosure.module.css';

interface DisclosureProps {
  children: ReactNode;
  /** Smaller summary, for a disclosure nested inside a dense row. */
  compact?: boolean;
  /** Short state shown beside the title, readable while the content is hidden. */
  meta?: ReactNode;
  onToggle: (open: boolean) => void;
  open: boolean;
  title: ReactNode;
}

/**
 * A native `details` disclosure, controlled so that its host can open it when
 * something inside needs attention — typically a validation error it would
 * otherwise keep out of sight.
 */
export function Disclosure({
  children,
  compact = false,
  meta,
  onToggle,
  open,
  title,
}: DisclosureProps) {
  return (
    <details
      className={compact ? `${styles.disclosure} ${styles.compact}` : styles.disclosure}
      onToggle={(event) => onToggle(event.currentTarget.open)}
      open={open}
    >
      <summary>
        <span aria-hidden="true" className={styles.chevron}>
          <Icon name="chevron-right" size={compact ? 12 : 14} />
        </span>
        <span>{title}</span>
        {meta ? <span className={styles.meta}>{meta}</span> : null}
      </summary>
      <div className={styles.content}>{children}</div>
    </details>
  );
}
