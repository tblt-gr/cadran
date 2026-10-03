import type { ReactNode } from 'react';
import styles from './ValuationForm.module.css';

/** A labelled control with its hint and inline error, shared by every field of the valuation form. */
export function FormField({
  children,
  error,
  hint,
  label,
  wide = false,
}: {
  children: ReactNode;
  error?: string;
  hint?: string;
  label: string;
  wide?: boolean;
}) {
  return (
    <div className={`${styles.field} ${wide ? styles.wide : ''}`}>
      <label className={styles.label}>
        <span>{label}</span>
        {children}
      </label>
      {hint ? <small className={styles.hint}>{hint}</small> : null}
      {error ? (
        <small className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </div>
  );
}
