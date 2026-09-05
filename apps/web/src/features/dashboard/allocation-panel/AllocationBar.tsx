import styles from './AllocationPanel.module.css';

interface AllocationBarProps {
  percent: string | null;
}

/**
 * The width of one allocation bar.
 *
 * The bar is decorative and hidden from assistive technology: the same
 * percentage is already written beside it as the canonical string the backend
 * produced. Reading that string as a `Number` here buys a pixel width, never a
 * displayed figure, and a share outside 0–100 % — a group weighed against a
 * small eligible base — is clamped so the bar cannot overflow its track.
 */
export function AllocationBar({ percent }: AllocationBarProps) {
  if (percent === null) {
    return <span className={styles.track} aria-hidden="true" />;
  }

  const width = Math.min(100, Math.max(0, Number(percent)));

  return (
    <span className={styles.track} aria-hidden="true">
      <span className={styles.fill} style={{ width: `${width}%` }} />
    </span>
  );
}
