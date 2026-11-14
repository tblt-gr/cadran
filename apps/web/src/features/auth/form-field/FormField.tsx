import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './FormField.module.css';

interface FormFieldProps {
  id: string;
  label: string;
  type: 'email' | 'password' | 'text';
  value: string;
  autoComplete: string;
  onChange: (value: string) => void;
  error?: string;
  required?: boolean;
  minLength?: number;
  disabled?: boolean;
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
  disabled = false,
}: FormFieldProps) {
  const { t } = useTranslation();
  const [revealed, setRevealed] = useState(false);
  const errorId = `${id}-error`;
  const secret = type === 'password';

  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <div className={styles.control}>
        <input
          id={id}
          type={secret && revealed ? 'text' : type}
          value={value}
          autoComplete={autoComplete}
          required={required}
          minLength={minLength}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          onChange={(event) => onChange(event.target.value)}
        />
        {secret ? (
          <button
            aria-label={t(revealed ? 'auth.passwordToggle.hide' : 'auth.passwordToggle.show')}
            aria-pressed={revealed}
            className={`icon-ghost ${styles.reveal}`}
            disabled={disabled}
            onClick={() => setRevealed((current) => !current)}
            type="button"
          >
            <Icon name={revealed ? 'eye-off' : 'eye'} size={18} />
          </button>
        ) : null}
      </div>
      {error ? (
        <p className={styles.error} id={errorId}>
          {error}
        </p>
      ) : null}
    </div>
  );
}
