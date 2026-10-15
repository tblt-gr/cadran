import { openSession } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { loginPath } from '@/features/auth/authPath';
import { FormField } from '@/features/auth/form-field/FormField';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import styles from './LoginForm.module.css';

type LoginError = 'invalidCredentials' | 'disabled' | 'throttled' | 'network';

/**
 * Email and password sign-in. Emits `onSuccess` once the server has set the
 * session cookie; the caller refetches the session to enter the app.
 */
export function LoginForm({
  onSuccess,
  disabled = false,
}: {
  onSuccess: () => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<LoginError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      const { response } = await withCsrfRetry(() =>
        openSession({ ...authApiOptions(), body: { email, password } }),
      );

      if (!response) {
        setError('network');

        return;
      }

      if (response.ok) {
        if (window.location.pathname === loginPath) {
          window.history.replaceState(null, '', '/');
        }
        onSuccess();

        return;
      }

      if (response.status === 401) {
        setError('invalidCredentials');
      } else if (response.status === 403) {
        setError('disabled');
      } else if (response.status === 429) {
        setError('throttled');
      } else {
        // 4xx malformed request, 5xx server: not a credential problem.
        setError('network');
      }
    } catch {
      setError('network');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit} noValidate aria-busy={disabled}>
      {error ? (
        <p className={styles.formError} role="alert">
          {t(`auth.login.errors.${error}`)}
        </p>
      ) : null}

      <FormField
        id="login-email"
        type="email"
        label={t('auth.fields.email')}
        autoComplete="username"
        value={email}
        onChange={setEmail}
        disabled={disabled}
      />
      <FormField
        id="login-password"
        type="password"
        label={t('auth.fields.password')}
        autoComplete="current-password"
        value={password}
        onChange={setPassword}
        disabled={disabled}
      />

      <button
        className={`primary-action ${styles.submit}`}
        type="submit"
        disabled={disabled || submitting}
      >
        {submitting ? t('auth.login.submitting') : t('auth.login.submit')}
      </button>
    </form>
  );
}
