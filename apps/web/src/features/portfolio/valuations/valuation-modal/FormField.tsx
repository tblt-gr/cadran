import { useId, type ReactNode } from 'react';
import styles from './ValuationForm.module.css';

/** A labelled control with its hint and inline error, shared by every field of the valuation form. */
export function FormField({
  children,
  error,
  hint,
  label,
  wide = false,
}: {
  children: (attributes: {
    id: string;
    'aria-describedby': string | undefined;
    'aria-invalid': true | undefined;
  }) => ReactNode;
  error?: string;
  hint?: string;
  label: string;
  wide?: boolean;
}) {
  const fieldId = useId();
  const hintId = `${fieldId}-hint`;
  const errorId = `${fieldId}-error`;
  return (
    <div className={`${styles.field} ${wide ? styles.wide : ''}`}>
      <label className={styles.label}>
        <span>{label}</span>
        {children({
          id: fieldId,
          'aria-invalid': error ? true : undefined,
          'aria-describedby':
            [hint ? hintId : undefined, error ? errorId : undefined].filter(Boolean).join(' ') ||
            undefined,
        })}
      </label>
      {hint ? (
        <small className={styles.hint} id={hintId}>
          {hint}
        </small>
      ) : null}
      {error ? (
        <small className={styles.error} id={errorId} role="alert">
          {error}
        </small>
      ) : null}
    </div>
  );
}
