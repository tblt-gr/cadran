import { useId } from 'react';
import { OperationField } from './OperationField';
import styles from './OperationForm.module.css';

/** A decimal typed as text: it never goes through Number, only through lib/decimal checks. */
export function OperationDecimalField({
  error,
  hint,
  label,
  onChange,
  value,
}: {
  error?: string;
  hint?: string;
  label: string;
  onChange: (value: string) => void;
  value: string;
}) {
  const errorId = useId();
  return (
    <OperationField errorId={errorId} error={error} label={label}>
      <input
        aria-invalid={!!error}
        aria-describedby={error ? errorId : undefined}
        autoComplete="off"
        inputMode="decimal"
        onChange={(event) => onChange(event.target.value)}
        value={value}
      />
      {hint ? <small className={styles.hint}>{hint}</small> : null}
    </OperationField>
  );
}
