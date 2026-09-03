import type { ReactNode } from 'react';
import styles from './FormField.module.css';

interface FormFieldProps {
  children: (ids: { fieldId: string; describedBy: string | undefined }) => ReactNode;
  error?: string;
  hint?: string;
  label: string;
  name: string;
}

/**
 * One labelled control with its hint or error message.
 *
 * The message lives outside the `<label>` and is tied to the control through
 * `aria-describedby`: a message nested inside the label would either be read as
 * part of the field name or, once the name is overridden, never announced at
 * all. That is the failure this component exists to prevent.
 */
export function FormField({ children, error, hint, label, name }: FormFieldProps) {
  const fieldId = `account-${name}`;
  const messageId = `${fieldId}-message`;
  const message = error ?? hint;

  return (
    <div className={styles.field}>
      <label htmlFor={fieldId}>{label}</label>
      {children({ fieldId, describedBy: message ? messageId : undefined })}
      {message ? (
        <small className={error ? styles.error : styles.hint} id={messageId}>
          {message}
        </small>
      ) : null}
    </div>
  );
}
