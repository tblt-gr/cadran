import { useId, type ReactNode } from 'react';
import styles from './FieldRow.module.css';

interface FieldRowProps {
  children: (ids: { fieldId: string; describedBy: string | undefined }) => ReactNode;
  error?: string;
  hint?: string;
  label: string;
}

/**
 * One labelled control with its hint or error. The message sits outside the
 * `<label>` and is tied to the control through `aria-describedby`, so it is
 * announced as help rather than folded into the field name.
 */
export function FieldRow({ children, error, hint, label }: FieldRowProps) {
  const fieldId = useId();
  const messageId = useId();
  const message = error ?? hint;

  return (
    <div className={styles.field}>
      <label htmlFor={fieldId}>{label}</label>
      {children({ fieldId, describedBy: message ? messageId : undefined })}
      {message ? (
        // An error can appear after the field already has focus — a balance
        // that only turns out malformed once a debounced preview settles, say
        // — with no focus move to make a screen reader re-read it. `polite`
        // on the error case alone announces that without interrupting typing;
        // a plain hint never changes on its own, so it stays silent.
        <small
          aria-live={error ? 'polite' : undefined}
          className={error ? styles.error : styles.hint}
          id={messageId}
        >
          {message}
        </small>
      ) : null}
    </div>
  );
}
