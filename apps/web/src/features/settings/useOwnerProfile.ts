import { getOwnerProfile, type OwnerProfile } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { profileRequestError } from '@/features/settings/profileError';

export const ownerProfileQueryKey = ['owner-profile'] as const;

/**
 * The signed-in owner's own account. Read from its own endpoint rather than
 * from the session probe, so the settings screen owns its loading and error
 * states instead of borrowing the authentication boundary's.
 */
export function useOwnerProfile() {
  return useQuery<OwnerProfile>({
    queryKey: ownerProfileQueryKey,
    queryFn: async ({ signal }) => {
      const result = await getOwnerProfile({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) {
        throw profileRequestError(result);
      }

      return result.data;
    },
    retry: false,
  });
}
