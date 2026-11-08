import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './Disclosure.module.css';

interface DisclosureProps {
  children: ReactNode;
  /** Smaller summary, for a disclosure nested inside a dense row. */
  compact?: boolean;
  disabled?: boolean;
  /** Short state shown beside the title, readable while the content is hidden. */
  meta?: ReactNode;
  /** Full-width summary: the title and chevron on the left, the meta at the far end. */
  spread?: boolean;
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
  disabled = false,
  meta,
  onToggle,
  open,
  spread = false,
  title,
}: DisclosureProps) {
  return (
    <details
      className={[styles.disclosure, compact && styles.compact, spread && styles.spread]
        .filter(Boolean)
        .join(' ')}
      onToggle={(event) => onToggle(event.currentTarget.open)}
      open={open}
    >
      <summary
        aria-disabled={disabled || undefined}
        tabIndex={disabled ? -1 : undefined}
        onClick={disabled ? (event) => event.preventDefault() : undefined}
      >
        <span className={styles.lead}>
          <span aria-hidden="true" className={styles.chevron}>
            <Icon name="chevron-right" size={compact ? 12 : 14} />
          </span>
          <span>{title}</span>
        </span>
        {meta ? <span className={styles.meta}>{meta}</span> : null}
      </summary>
      <div className={styles.content}>{children}</div>
    </details>
  );
}
