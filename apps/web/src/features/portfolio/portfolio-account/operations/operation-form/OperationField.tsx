import type { ReactNode } from 'react';
import styles from './OperationForm.module.css';

/** A labelled control with its inline error, shared by every field of the form. */
export function OperationField({
  children,
  error,
  errorId,
  label,
  wide = false,
}: {
  children: ReactNode;
  error?: string;
  errorId?: string;
  label: string;
  wide?: boolean;
}) {
  return (
    <label className={wide ? styles.wide : undefined}>
      <span>{label}</span>
      {children}
      {error ? (
        <small id={errorId} className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </label>
  );
}
