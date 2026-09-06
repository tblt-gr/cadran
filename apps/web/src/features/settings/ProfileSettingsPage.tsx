import { changeOwnerPassword, updateOwnerProfile } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { sessionQueryKey } from '@/features/auth/useSession';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  ProfileRequestError,
  profileErrorKind,
  profileRequestError,
} from '@/features/settings/profileError';
import { ownerProfileQueryKey, useOwnerProfile } from '@/features/settings/useOwnerProfile';
import { ChangePasswordForm, type PasswordChange } from './change-password-form/ChangePasswordForm';
import { DisplayNameForm } from './display-name-form/DisplayNameForm';
import styles from './ProfileSettingsPage.module.css';

type Saved = 'password' | 'profile';

/**
 * Account settings. Both sections act on the signed-in owner's own account;
 * neither takes an account identifier, so there is nothing here that could
 * point at somebody else's record.
 */
export function ProfileSettingsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const profile = useOwnerProfile();
  const [saved, setSaved] = useState<Saved | null>(null);
  // Remounts the password form after a success so the three fields are dropped
  // rather than left holding a credential.
  const [passwordFormKey, setPasswordFormKey] = useState(0);

  const rename = useMutation({
    mutationFn: async (displayName: string) => {
      const result = await withCsrfRetry(() =>
        updateOwnerProfile({ ...authApiOptions(), body: { displayName } }),
      );
      if (!result.response?.ok || !result.data) {
        throw profileRequestError(result);
      }

      return result.data;
    },
    onSuccess: async () => {
      setSaved('profile');
      // The display name is also part of the session view the shell reads.
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ownerProfileQueryKey }),
        queryClient.invalidateQueries({ queryKey: sessionQueryKey }),
      ]);
    },
  });

  const changePassword = useMutation({
    mutationFn: async (change: PasswordChange) => {
      const result = await withCsrfRetry(() =>
        changeOwnerPassword({ ...authApiOptions(), body: change }),
      );
      if (!result.response?.ok) {
        throw profileRequestError(result);
      }
    },
    onSuccess: async () => {
      setSaved('password');
      setPasswordFormKey((key) => key + 1);
      // The server issued a new session identifier: re-read the session so a
      // stale view of it cannot outlive the credential it was bound to.
      await queryClient.invalidateQueries({ queryKey: sessionQueryKey });
    },
  });

  const unauthorized =
    profile.error instanceof ProfileRequestError && profile.error.kind === 'unauthorized';

  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="settings-intro-title">
        <div>
          <p>{t('settings.eyebrow')}</p>
          <h2 id="settings-intro-title">{t('settings.title')}</h2>
          <span>{t('settings.description')}</span>
        </div>
      </section>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`settings.saved.${saved}`)}</Toast>
      ) : null}

      {profile.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h2>{t('settings.loading')}</h2>
        </section>
      ) : profile.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>{t(unauthorized ? 'settings.unauthorized.title' : 'settings.error.title')}</h2>
          <p>
            {t(unauthorized ? 'settings.unauthorized.description' : 'settings.error.description')}
          </p>
          {!unauthorized ? (
            <button
              className="secondary-action"
              onClick={() => void profile.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : (
        <div className={styles.sections}>
          <section className={`card ${styles.section}`} aria-labelledby="settings-profile-title">
            <header>
              <h3 id="settings-profile-title">{t('settings.profile.title')}</h3>
              <p>{t('settings.profile.description')}</p>
            </header>
            <dl className={styles.readOnly}>
              <dt>{t('settings.profile.fields.email')}</dt>
              <dd>{profile.data.email}</dd>
            </dl>
            <DisplayNameForm
              currentDisplayName={profile.data.displayName}
              key={profile.data.displayName}
              onSubmit={(displayName) => rename.mutate(displayName)}
              pending={rename.isPending}
              submitError={profileErrorKind(rename.error, rename.isError)}
            />
          </section>

          <section className={`card ${styles.section}`} aria-labelledby="settings-password-title">
            <header>
              <h3 id="settings-password-title">{t('settings.password.title')}</h3>
              <p>{t('settings.password.description')}</p>
            </header>
            <ChangePasswordForm
              key={passwordFormKey}
              onSubmit={(change) => changePassword.mutate(change)}
              pending={changePassword.isPending}
              submitError={profileErrorKind(changePassword.error, changePassword.isError)}
            />
          </section>
        </div>
      )}
    </div>
  );
}
