import { closeSession } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from './LogoutButton.module.css';

/**
 * Ends the session and drops every cached query so no authenticated data
 * survives on the login screen. The cache is cleared even if the request fails,
 * so a broken network cannot strand the user in an apparently authenticated UI.
 */
export function LogoutButton() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function handleClick() {
    setPending(true);

    try {
      await closeSession({ ...authApiOptions() });
    } catch {
      // Ignore: the cache reset below still returns the UI to a safe state.
    } finally {
      queryClient.clear();
      setPending(false);
    }
  }

  return (
    <button
      className={`secondary-action ${styles.button}`}
      type="button"
      disabled={pending}
      onClick={() => void handleClick()}
    >
      {t('auth.logout')}
    </button>
  );
}
