import { listCategories, type Category } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PAGE_SIZE = 100;
/** Bounds the lookup: 1000 categories is far beyond a personal chart of accounts. */
const MAX_PAGES = 10;

/**
 * The category a recurrence carries, with its archived siblings: a recurrence keeps pointing
 * at a category archived after it was saved, and the card must still name it.
 */
export function useRecurrenceCategory(categoryId: string | null): Category | undefined {
  const categories = useQuery({
    queryKey: ['recurrence-categories'],
    enabled: categoryId !== null,
    queryFn: async ({ signal }) => {
      const items: Category[] = [];
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const result = await listCategories({
          ...authApiOptions(),
          query: { includeArchived: true, page, pageSize: PAGE_SIZE },
          signal,
        });
        if (!result.response?.ok || !result.data) throw new Error('recurrence-categories');
        items.push(...result.data.items);
        if (items.length >= result.data.total) break;
      }

      return items;
    },
    retry: false,
    staleTime: 60_000,
  });

  return categoryId === null ? undefined : categories.data?.find((c) => c.id === categoryId);
}
