import styles from './ColorDot.module.css';

/**
 * The one shape a user colour takes in the interface: a small dot beside the label it belongs to.
 * The colour must already be validated (canonical hex); a missing colour draws nothing.
 */
export function ColorDot({ color }: { color: string | null }) {
  if (color === null) return null;

  return (
    <span aria-hidden="true" className={styles.dot} data-color-dot>
      <svg className={styles.fill} viewBox="0 0 10 10">
        <circle cx="5" cy="5" fill={color} r="4.5" stroke="var(--border-strong)" />
      </svg>
    </span>
  );
}
