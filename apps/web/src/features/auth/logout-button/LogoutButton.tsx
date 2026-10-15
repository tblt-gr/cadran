import { closeSession, type SessionState } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { sessionQueryKey } from '@/features/auth/useSession';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import styles from './LogoutButton.module.css';

/**
 * Ends the session and drops every cached query so no authenticated data
 * survives on the login screen. The cache is reset even if the request fails,
 * so a broken network cannot strand the user in an apparently authenticated UI.
 *
 * `clear()` alone would leave the gate's session observer on its old,
 * authenticated result until a reload; the session entry is therefore marked
 * signed out first (the gate swaps to the login screen and unmounts the app),
 * then every other query is removed and the server state is confirmed.
 */
export function LogoutButton() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function handleClick() {
    setPending(true);

    try {
      // withCsrfRetry: a tab open past the token's lifetime would otherwise get
      // a 403 here and stay signed in.
      await withCsrfRetry(() => closeSession({ ...authApiOptions() }));
    } catch {
      // Ignore: the cache reset below still returns the UI to a safe state.
    } finally {
      queryClient.setQueryData<SessionState>(sessionQueryKey, (session) =>
        session === undefined
          ? undefined
          : { ...session, authenticated: false, user: null, workspace: null },
      );
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== sessionQueryKey[0] });
      void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
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
