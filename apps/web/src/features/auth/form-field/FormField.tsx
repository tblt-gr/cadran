import styles from './FormField.module.css';

interface FormFieldProps {
  id: string;
  label: string;
  type: 'email' | 'password';
  value: string;
  autoComplete: string;
  onChange: (value: string) => void;
  error?: string;
  required?: boolean;
  minLength?: number;
}

/**
 * One labelled text input with its inline error, wired for assistive tech
 * (`htmlFor`, `aria-invalid`, `aria-describedby`).
 */
export function FormField({
  id,
  label,
  type,
  value,
  autoComplete,
  onChange,
  error,
  required = true,
  minLength,
}: FormFieldProps) {
  const errorId = `${id}-error`;

  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        type={type}
        value={value}
        autoComplete={autoComplete}
        required={required}
        minLength={minLength}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        onChange={(event) => onChange(event.target.value)}
      />
      {error ? (
        <p className={styles.error} id={errorId}>
          {error}
        </p>
      ) : null}
    </div>
  );
}
