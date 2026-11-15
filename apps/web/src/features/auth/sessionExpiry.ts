import type { client as apiClient } from '@cadran/api-client/client';
import type { QueryClient } from '@tanstack/react-query';
import { sessionQueryKey } from '@/features/auth/useSession';

const sessionPath = '/api/v1/session';

/**
 * Any 401 outside the session endpoint means the session ended while the app was open:
 * refetch the session so AuthGate returns to the sign-in screen. The response is passed
 * through unchanged, so the caller still receives its own error.
 */
export function registerSessionExpiry(
  client: typeof apiClient,
  queryClient: QueryClient,
): () => void {
  const intercept = (response: Response, request: Request): Response => {
    if (
      response.status === 401 &&
      new URL(request.url).pathname !== sessionPath &&
      queryClient.isFetching({ queryKey: sessionQueryKey }) === 0
    ) {
      void queryClient.invalidateQueries({ queryKey: sessionQueryKey });
    }

    return response;
  };

  client.interceptors.response.use(intercept);

  return () => client.interceptors.response.eject(intercept);
}
