import { getSession, type SessionState } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

export const sessionQueryKey = ['session'] as const;

/**
 * Server-owned auth state. The backend decides whether the request is
 * authenticated and whether first-run password setup is still pending; the SPA
 * only renders the matching boundary.
 */
export function useSession() {
  return useQuery<SessionState>({
    queryKey: sessionQueryKey,
    queryFn: async ({ signal }) => {
      const response = await getSession({ ...authApiOptions(), signal, throwOnError: true });

      return response.data;
    },
    retry: false,
  });
}
