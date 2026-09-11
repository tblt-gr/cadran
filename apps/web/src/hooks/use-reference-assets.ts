import { listAssets, type Asset } from '@cadran/api-client';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The system asset reference: every denomination the API knows, with its
 * display and storage precision. Shared by every screen that needs to
 * resolve a code to its properties, so the query and its key stay in one
 * place rather than being re-declared per caller.
 */
export function useReferenceAssets(): UseQueryResult<{ items: Asset[] }> {
  return useQuery({
    queryKey: ['reference-assets'],
    queryFn: async ({ signal }) => {
      const result = await listAssets({
        ...authApiOptions(),
        query: { page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the asset reference.');
      }
      return result.data;
    },
    retry: false,
  });
}
