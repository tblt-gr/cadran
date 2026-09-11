import { listCategories, type CategoryType } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

export function useCategoryCandidates(type: CategoryType, parentEligible: boolean, search: string) {
  return useQuery({
    queryKey: ['category-candidates', type, parentEligible, search],
    queryFn: async ({ signal }) => {
      const result = await listCategories({
        ...authApiOptions(),
        query: { type, search: search || undefined, parentEligible, page: 1, perPage: 50 },
        signal,
      });
      if (!result.response?.ok || !result.data)
        throw new Error('Unable to load category candidates.');
      return result.data;
    },
    placeholderData: (previous) => previous,
    retry: false,
  });
}
