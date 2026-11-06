import { useId, type InputHTMLAttributes } from 'react';
import styles from './PortfolioTakeoverForm.module.css';

/** A labelled input with its optional hint and inline error, wired for assistive technology. */
export function TakeoverInput({
  error,
  hint,
  label,
  ...input
}: {
  error?: string;
  hint?: string;
  label: string;
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'aria-invalid' | 'aria-describedby' | 'id'>) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const described = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ');
  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <input
        {...input}
        aria-describedby={described || undefined}
        aria-invalid={error ? true : undefined}
        id={id}
      />
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
