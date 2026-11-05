import { listCategories, type Category } from '@cadran/api-client';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;
/** Bounds the lookup: 1000 categories is far beyond a personal chart of accounts. */
const MAX_PAGES = 10;

/**
 * Every category of the workspace, archived ones included, keyed by id. Screens that only hold
 * an identifier (a URL filter, a recurrence saved before its category was archived) resolve its
 * label here; an id missing from the map is absent from the workspace, not merely unloaded.
 */
export function useCategoryIdentities(
  enabled = true,
): UseQueryResult<Map<string, Category>, Error> {
  return useQuery({
    queryKey: ['categories', 'identities'],
    enabled,
    queryFn: async ({ signal }) => {
      const items: Category[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const result = await listCategories({
          ...authApiOptions(),
          query: { includeArchived: true, page, pageSize: PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) throw new Error('category-identities');
        items.push(...result.data.items);
        if (items.length >= result.data.total) break;
      }

      return new Map(items.map((category) => [category.id, category]));
    },
    retry: false,
    staleTime: 60_000,
  });
}
