import { readAccountGroupComposition } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { accountRequestError, requestFailed } from '@/features/accounts/accountError';
import { authApiOptions } from '@/features/auth/apiOptions';

/** Server-calculated split of one parent group at the overview's valuation date. */
export function useGroupComposition(groupId: string, asOf: string) {
  return useQuery({
    queryKey: ['account-groups', groupId, 'composition', asOf],
    queryFn: async ({ signal }) => {
      const result = await readAccountGroupComposition({
        ...authApiOptions(),
        path: { id: groupId },
        query: { asOf },
        signal,
      });
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data!;
    },
    retry: false,
  });
}
