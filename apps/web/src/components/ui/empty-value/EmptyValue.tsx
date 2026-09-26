import styles from './EmptyValue.module.css';

interface EmptyValueProps {
  /** Why nothing is shown, for instance "Non calculable" or "À venir". Never visible. */
  label: string;
  /** More specific cause, joined to the label for assistive technology and the tooltip. */
  reason?: string | null;
}

/**
 * A cell with nothing to show: a plain "-" for sighted readers, the label and the
 * reason as visually hidden text and a tooltip so the absence never relies on colour.
 */
export function EmptyValue({ label, reason = null }: EmptyValueProps) {
  const title = reason ? `${label} : ${reason}` : label;

  return (
    <span className={styles.empty} title={title}>
      <span aria-hidden="true">-</span>
      <span className="sr-only">{label}</span>
      {reason ? <span className="sr-only">{reason}</span> : null}
    </span>
  );
}
