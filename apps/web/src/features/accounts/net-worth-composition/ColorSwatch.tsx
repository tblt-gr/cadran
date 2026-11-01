import { groupColor } from '@/features/accounts/group-color';
import styles from './NetWorthCompositionCard.module.css';

/** Decorative dot matching a donut slice; the account name next to it carries the meaning. */
export function ColorSwatch({ color }: { color: string | null }) {
  return (
    <svg aria-hidden="true" className={styles.swatch} data-swatch viewBox="0 0 10 10">
      <circle cx="5" cy="5" fill={groupColor(color)} r="4.5" stroke="var(--border-strong)" />
    </svg>
  );
}
