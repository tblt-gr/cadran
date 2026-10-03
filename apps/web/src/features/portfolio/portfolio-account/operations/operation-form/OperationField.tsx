import type { ReactNode } from 'react';
import styles from './OperationForm.module.css';

/** A labelled control with its inline error, shared by every field of the form. */
export function OperationField({
  children,
  error,
  label,
  wide = false,
}: {
  children: ReactNode;
  error?: string;
  label: string;
  wide?: boolean;
}) {
  return (
    <label className={wide ? styles.wide : undefined}>
      <span>{label}</span>
      {children}
      {error ? (
        <small className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </label>
  );
}
