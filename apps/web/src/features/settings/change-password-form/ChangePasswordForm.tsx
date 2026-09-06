import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/auth/form-field/FormField';
import type { ProfileErrorKind } from '@/features/settings/profileError';
import styles from './ChangePasswordForm.module.css';

// Mirrors the ChangePasswordRequest bounds in the API contract. The server
// stays the authority; this only spares an obvious round-trip.
const MIN_LENGTH = 12;
const MAX_LENGTH = 128;

export interface PasswordChange {
  currentPassword: string;
  newPassword: string;
}

interface ChangePasswordFormProps {
  onSubmit: (change: PasswordChange) => void;
  pending: boolean;
  submitError: ProfileErrorKind | null;
}

/**
 * Replaces the account password. The current password is required in the same
 * request: the server re-verifies it, so a browser left unattended cannot be
 * used to take the account over.
 */
export function ChangePasswordForm({ onSubmit, pending, submitError }: ChangePasswordFormProps) {
  const { t } = useTranslation();
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [showErrors, setShowErrors] = useState(false);

  // Code points, like the server's mb_strlen policy.
  const outOfBounds = [...newPassword].length < MIN_LENGTH || [...newPassword].length > MAX_LENGTH;
  const mismatch = confirmation !== newPassword;
  const missingCurrent = currentPassword === '';
  // A password manager routinely fills all three fields with the stored value.
  // Catching that here spares a round-trip whose only outcome would be the
  // server's reused-password refusal.
  const reused = currentPassword !== '' && newPassword === currentPassword;

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    if (missingCurrent || outOfBounds || mismatch || reused) {
      setShowErrors(true);

      return;
    }

    onSubmit({ currentPassword, newPassword });
  }

  // The two field-level server refusals are attached to the field they concern;
  // anything else is a form-level message.
  const currentPasswordError =
    submitError === 'currentPassword' ? t('settings.password.errors.currentPassword') : undefined;
  const newPasswordError =
    submitError === 'reused'
      ? t('settings.password.errors.reused')
      : submitError === 'weakPassword'
        ? t('settings.password.errors.length', { min: MIN_LENGTH, max: MAX_LENGTH })
        : undefined;
  const formError =
    submitError !== null && currentPasswordError === undefined && newPasswordError === undefined
      ? t(`settings.password.errors.${submitError}`)
      : null;

  return (
    <form className={styles.form} onSubmit={handleSubmit} noValidate>
      {formError ? (
        <p className={styles.formError} role="alert">
          {formError}
        </p>
      ) : null}

      <FormField
        id="settings-current-password"
        type="password"
        label={t('settings.password.fields.currentPassword')}
        autoComplete="current-password"
        value={currentPassword}
        onChange={setCurrentPassword}
        error={
          currentPasswordError ??
          (showErrors && missingCurrent
            ? t('settings.password.errors.currentPasswordRequired')
            : undefined)
        }
      />

      <FormField
        id="settings-new-password"
        type="password"
        label={t('settings.password.fields.newPassword')}
        autoComplete="new-password"
        value={newPassword}
        minLength={MIN_LENGTH}
        onChange={setNewPassword}
        error={
          newPasswordError ??
          (showErrors && outOfBounds
            ? t('settings.password.errors.length', { min: MIN_LENGTH, max: MAX_LENGTH })
            : showErrors && reused
              ? t('settings.password.errors.reused')
              : undefined)
        }
      />
      <p className={styles.hint}>{t('settings.password.hint', { min: MIN_LENGTH })}</p>

      <FormField
        id="settings-confirm-password"
        type="password"
        label={t('settings.password.fields.confirmPassword')}
        autoComplete="new-password"
        value={confirmation}
        onChange={setConfirmation}
        error={showErrors && mismatch ? t('settings.password.errors.mismatch') : undefined}
      />

      <button className={`primary-action ${styles.submit}`} type="submit" disabled={pending}>
        {pending ? t('settings.password.submitting') : t('settings.password.submit')}
      </button>
    </form>
  );
}
