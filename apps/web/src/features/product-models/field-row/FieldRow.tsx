import { useId, type ReactNode } from 'react';
import styles from './FieldRow.module.css';

interface FieldRowProps {
  children: (ids: { fieldId: string; describedBy: string | undefined }) => ReactNode;
  error?: string;
  hint?: string;
  label: string;
  /**
   * Announce the message whenever it changes, for the rows whose verdict can
   * arrive on its own — a debounced preview settling, say — rather than in
   * answer to a submit the reader just made. Off by default: a form that
   * flips every field's error at once on submit would otherwise queue them
   * all at the reader.
   */
  liveMessage?: boolean;
}

/**
 * One labelled control with its hint or error. The message sits outside the
 * `<label>` and is tied to the control through `aria-describedby`, so it is
 * announced as help rather than folded into the field name.
 */
export function FieldRow({ children, error, hint, label, liveMessage = false }: FieldRowProps) {
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
        // — with no focus move to make a screen reader re-read it. The region
        // therefore has to be live from the first render: several
        // screen-reader and browser pairs skip the announcement of a region
        // that only becomes live at the instant its text changes. A hint
        // never changes on its own, so a live row stays silent until an
        // error actually replaces it.
        <small
          aria-live={liveMessage ? 'polite' : undefined}
          className={error ? styles.error : styles.hint}
          id={messageId}
        >
          {message}
        </small>
      ) : null}
    </div>
  );
}
