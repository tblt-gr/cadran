import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/auth/form-field/FormField';
import type { ProfileErrorKind } from '@/features/settings/profileError';
import styles from './DisplayNameForm.module.css';

// Mirrors the UpdateProfileRequest bounds in the API contract. The server stays
// the authority; this only spares an obvious round-trip.
const MAX_LENGTH = 100;

interface DisplayNameFormProps {
  currentDisplayName: string;
  onSubmit: (displayName: string) => void;
  pending: boolean;
  submitError: ProfileErrorKind | null;
}

/**
 * Edits the display name shown across the application. The field is prefilled
 * with the stored value, so the owner amends rather than retypes.
 */
export function DisplayNameForm({
  currentDisplayName,
  onSubmit,
  pending,
  submitError,
}: DisplayNameFormProps) {
  const { t } = useTranslation();
  const [displayName, setDisplayName] = useState(currentDisplayName);
  const [showErrors, setShowErrors] = useState(false);

  // Count Unicode code points and ignore surrounding whitespace, matching the
  // server's rule; a naive string length would over-count anything outside the
  // basic multilingual plane.
  const trimmed = displayName.trim();
  const outOfBounds = trimmed === '' || [...trimmed].length > MAX_LENGTH;
  const unchanged = trimmed === currentDisplayName;

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    if (outOfBounds) {
      setShowErrors(true);

      return;
    }

    onSubmit(trimmed);
  }

  return (
    <form className={styles.form} onSubmit={handleSubmit} noValidate>
      {submitError ? (
        <p className={styles.formError} role="alert">
          {t(`settings.profile.errors.${submitError}`)}
        </p>
      ) : null}

      <FormField
        id="settings-display-name"
        type="text"
        label={t('settings.profile.fields.displayName')}
        autoComplete="name"
        value={displayName}
        onChange={setDisplayName}
        error={
          showErrors && outOfBounds
            ? t('settings.profile.errors.displayNameLength', { max: MAX_LENGTH })
            : undefined
        }
      />

      <button
        className={`primary-action ${styles.submit}`}
        type="submit"
        disabled={pending || unchanged}
      >
        {pending ? t('settings.profile.submitting') : t('settings.profile.submit')}
      </button>
    </form>
  );
}
