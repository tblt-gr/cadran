import { defineInitialPassword } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { FormField } from '@/features/auth/form-field/FormField';
import styles from './CreatePasswordForm.module.css';

// Mirrors the DefinePasswordRequest bounds in the API contract. The server
// stays the authority; this only spares an obvious round-trip.
const MIN_LENGTH = 12;
const MAX_LENGTH = 128;

type SubmitError = 'policy' | 'network';

/**
 * First-run screen: the provisioned owner sets their password once. Emits
 * `onDone` after the server accepts it (or reports it already exists).
 */
export function CreatePasswordForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showErrors, setShowErrors] = useState(false);
  const [submitError, setSubmitError] = useState<SubmitError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Count Unicode code points, matching the server's mb_strlen policy; a naive
  // string length would over-count anything outside the BMP.
  const codePointLength = [...password].length;
  const outOfBounds = codePointLength < MIN_LENGTH || codePointLength > MAX_LENGTH;
  const mismatch = confirmation !== password;

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitError(null);

    if (outOfBounds || mismatch) {
      setShowErrors(true);

      return;
    }

    setSubmitting(true);

    try {
      const { response } = await defineInitialPassword({ ...authApiOptions(), body: { password } });

      if (!response) {
        setSubmitError('network');

        return;
      }

      // 409 means a concurrent first-run set it first: still done, let the
      // caller refetch and land on the login screen.
      if (response.ok || response.status === 409) {
        onDone();

        return;
      }

      // 422 is the server's own policy check; anything else (400, 5xx) is not
      // about the password content.
      setSubmitError(response.status === 422 ? 'policy' : 'network');
    } catch {
      setSubmitError('network');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit} noValidate>
      {submitError ? (
        <p className={styles.formError} role="alert">
          {t(`auth.createPassword.errors.${submitError}`)}
        </p>
      ) : null}

      <FormField
        id="new-password"
        type="password"
        label={t('auth.fields.newPassword')}
        autoComplete="new-password"
        value={password}
        minLength={MIN_LENGTH}
        onChange={setPassword}
        error={
          showErrors && outOfBounds
            ? t('auth.createPassword.errors.length', { min: MIN_LENGTH, max: MAX_LENGTH })
            : undefined
        }
      />
      <p className={styles.hint}>{t('auth.createPassword.hint', { min: MIN_LENGTH })}</p>

      <FormField
        id="confirm-password"
        type="password"
        label={t('auth.fields.confirmPassword')}
        autoComplete="new-password"
        value={confirmation}
        onChange={setConfirmation}
        error={showErrors && mismatch ? t('auth.createPassword.errors.mismatch') : undefined}
      />

      <button className={`primary-action ${styles.submit}`} type="submit" disabled={submitting}>
        {submitting ? t('auth.createPassword.submitting') : t('auth.createPassword.submit')}
      </button>
    </form>
  );
}
